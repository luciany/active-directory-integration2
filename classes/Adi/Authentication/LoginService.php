<?php
if (!defined('ABSPATH')) {
	die('Access denied.');
}

if (class_exists('NextADInt_Adi_Authentication_LoginService')) {
	return;
}

/**
 * Entrypoint for the authentication process of WordPress.
 *
 * This class registers the "authenticate" callback in WordPress and is responsible for the authentication process.
 *
 * @author Tobias Hellmann <the@neos-it.de>
 * @access pubic
 */
class NextADInt_Adi_Authentication_LoginService
{
	/* @var NextADInt_Adi_Authentication_Persistence_FailedLoginRepository $failedLogin */
	private $failedLogin;

	/* @var NextADInt_Ldap_Connection $ldapConnection */
	private $ldapConnection;

	/* @var NextADInt_Multisite_Configuration_Service $configuration */
	private $configuration;

	/* @var NextADInt_Adi_User_Manager $userManager */
	private $userManager;

	/* @var NextADInt_Adi_Mail_Notification $mailNotification */
	private $mailNotification;

	/* @var NextADInt_Adi_Authentication_Ui_ShowBlockedMessage $userBlockedMessage */
	private $userBlockedMessage;

	/** @var NextADInt_Ldap_Attribute_Service $attributeService */
	private $attributeService;

	/* @var Logger $logger */
	private $logger;

	/**
	 * @var NextADInt_Adi_Role_Manager $roleManager
	 */
	private $roleManager;

	private $currentUserAuthenticated;

	/**
	 * @param NextADInt_Adi_Authentication_Persistence_FailedLoginRepository|null $failedLogin
	 * @param NextADInt_Multisite_Configuration_Service                           $configuration
	 * @param NextADInt_Ldap_Connection                                           $ldapConnection
	 * @param NextADInt_Adi_User_Manager                                          $userManager
	 * @param NextADInt_Adi_Mail_Notification|null                                $mailNotification
	 * @param NextADInt_Adi_Authentication_Ui_ShowBlockedMessage|null             $userBlockedMessage
	 * @param NextADInt_Ldap_Attribute_Service                                    $attributeService
	 * @param NextADInt_Adi_Role_Manager                                          $roleManager
	 */
	public function __construct(NextADInt_Adi_Authentication_Persistence_FailedLoginRepository $failedLogin = null,
								NextADInt_Multisite_Configuration_Service $configuration,
								NextADInt_Ldap_Connection $ldapConnection,
								NextADInt_Adi_User_Manager $userManager,
								NextADInt_Adi_Mail_Notification $mailNotification = null,
								NextADInt_Adi_Authentication_Ui_ShowBlockedMessage $userBlockedMessage = null,
								NextADInt_Ldap_Attribute_Service $attributeService,
								NextADInt_Adi_Role_Manager $roleManager
	) {
		$this->failedLogin = $failedLogin;
		$this->configuration = $configuration;
		$this->ldapConnection = $ldapConnection;
		$this->userManager = $userManager;
		$this->mailNotification = $mailNotification;
		$this->userBlockedMessage = $userBlockedMessage;
		$this->attributeService = $attributeService;
		$this->roleManager = $roleManager;

		$this->logger = Logger::getLogger(__CLASS__);

		$this->currentUserAuthenticated = false;
	}

	/**
	 * Callback handler for WordPress which adds this class to the "authenticate" callback.
	 */
	public function register()
	{
		add_filter('authenticate', array($this, 'authenticate'), 10, 3);

		// disable 'lost password' feature
		$enableLostPasswordRecovery = $this->configuration->getOptionValue(
			NextADInt_Adi_Configuration_Options::ENABLE_LOST_PASSWORD_RECOVERY
		);

		if (!$enableLostPasswordRecovery) {
			add_filter('allow_password_reset', '__return_false');
			add_action('lost_password', array($this, 'disableLostPassword'));
		}
	}

	/**
	 * Prevent WordPress' password recovery b/c password is managed by Active Directory.
	 */
	public function disableLostPassword()
	{
		$message = esc_html__(
			'Lost Password feature has been disabled by the "Active Directory Integration 2" plugin.', NEXT_AD_INT_I18N
		);

		wp_die($message);
	}

	/**
	 * Check if the user can be authenticated and update his local WordPress account based upon his Active Directory profile.
	 * ADI implicitly uses the authentication against the userPrincipalName by authenticating with the full UPN username.
	 *
	 * @param object|null $user not used
	 * @param string      $login
	 * @param string      $password
	 *
	 * @return WP_Error|WP_User|false
	 */
	public function authenticate($user = null /* required for WordPress callback */, $login = '', $password = '')
	{
		if (!$login) {
			return false;
		}

		$this->logger->info('A user tries to log in.');

		// ADI-367 Detect xmlrpc.php access
		$xmlrpcEnabled = $this->configuration->getOptionValue(NextADInt_Adi_Configuration_Options::ALLOW_XMLRPC_LOGIN);
		$page = $_SERVER['PHP_SELF'];

		if (strpos($page, 'xmlrpc.php') !== false) {

			if (!$xmlrpcEnabled) {
				$this->logger->warn("XML RPC Login detected ! Preventing further authentication.");
				wp_die(__("Next Adi prevents XML RPC authentication!", NEXT_AD_INT_I18N));
			} else {
				$this->logger->warn("XML RPC Login detected ! XML RPC authentication is enabled. Continuing");
			}

		}

        // unquote backlash from username
        // https://wordpress.org/support/topic/fatal-error-after-login-and-suffix-question/
        $login = stripcslashes($login);

		// login must not be empty or user must not be an admin
		if (!$this->requiresActiveDirectoryAuthentication($login)) {
			return false;
		}

		// login should be case insensitive
		$password = stripslashes($password);

		$credentials = self::createCredentials($login, $password);
		$suffixes = $this->detectAuthenticatableSuffixes($credentials->getUpnSuffix());

		return $this->tryAuthenticatableSuffixes(
			$credentials,
			$suffixes
		);
	}

	/**
	 * Try every given suffix and authenticate with it against the Active Directory. The first authenticatable suffix is used.
	 *
	 * @param NextADInt_Adi_Authentication_Credentials $credentials
	 * @param array                          $suffixes
	 *
	 * @return bool
	 * @throws Exception
	 */
	public function tryAuthenticatableSuffixes(NextADInt_Adi_Authentication_Credentials $credentials, $suffixes = array())
	{
		NextADInt_Core_Assert::notNull($credentials, "authentication must not be null");
		NextADInt_Core_Assert::notNull($suffixes, "suffixes must not be null");

		$this->logger->debug("$credentials' with authenticatable suffixes: '" . implode(", ", $suffixes) . "'.");

        // add an empty account suffix for supporting usernames without an suffix like TEST\klammer
        // https://github.com/NeosIT/active-directory-integration2/issues/
        if ($this->configuration->getOptionValue(NextADInt_Adi_Configuration_Options::ALLOW_DOWN_LEVEL_LOGON_NAME)) {
            $suffixes[] = '';
        }

		// authenticate at AD
		foreach ($suffixes as $suffix) {
			$success = $this->authenticateAtActiveDirectory(
				$credentials->getUpnUsername(),
				$suffix,
				$credentials->getPassword()
			);

			if ($success) {
				// ADI-204: After authentication the identified UPN suffix must be updated
				$credentials->setUpnSuffix($suffix);

				return $this->postAuthentication($credentials);
			}

		}

		$this->logger->warn('Login for ' . $credentials . ' failed: none of the suffixes succeeded');

		return false;
	}

	/**
	 * Create a new instance of Adi_Authentication_ActiveDirectory
	 *
	 * @param $login
	 * @param $password
	 *
	 * @return NextADInt_Adi_Authentication_Credentials
	 */
	public static function createCredentials($login, $password)
	{
		return new NextADInt_Adi_Authentication_Credentials($login, $password);
	}

	/**
	 * Check if the Active Directory authentication is required or not.
	 * If the username is empty or the user is WordPress first/admin account, an Active Directory authentication will *not* be executed.
	 *
	 * @param string $login Username or e-mail address
	 *
	 * @return bool
	 */
	public function requiresActiveDirectoryAuthentication($login)
	{
		// stop if username is empty
		if (empty($login)) {
			$this->logger->warn('Username is empty. Authentication failed.');

			return false;
		}

		// don't use Active Directory for WordPress' admin user (ID 1)
		$user = $this->getWordPressUser($login);

		if ($user && ($user->ID === 1)) {
			$this->logger->debug('User with ID 1 will never be authenticated by this plugin.');

			return false;
		}

		if ($this->isUsernameExcludedFromAuthentication($login)) {
			$this->logger->debug("User '$login' is explicitly excluded from Active Directory authentication by configuration setting");

			return false;
		}

		// user must be authenticated by Active Directory
		return true;
	}

	/**
	 * Return whether the given username is excluded from authentication. This method is completely case-insensitive, so if the excluded usernames are
	 * "admin@test.ad;user@test.ad" it will return true for "admin@test.ad", "ADMIN@test.ad", "user@test.ad", "usER@test.AD" and so on.
	 *
	 * @access package
	 *
	 * @param string $username
	 *
	 * @return bool
	 */
	function isUsernameExcludedFromAuthentication($username)
	{
		$excludes = NextADInt_Core_Util_StringUtil::toLowerCase($this->configuration->getOptionValue(NextADInt_Adi_Configuration_Options::EXCLUDE_USERNAMES_FROM_AUTHENTICATION));
		$excludedUsernames = explode(';', $excludes);

		return in_array(NextADInt_Core_Util_StringUtil::toLowerCase($username), $excludedUsernames);
	}

	/**
	 * Get account suffix for given credentials
	 *
	 * @param string $suffix
	 *
	 * @return array
	 */
	function detectAuthenticatableSuffixes($suffix)
	{
		// get all account suffixes from the settings
		$suffixes = $this->configuration->getOptionValue(NextADInt_Adi_Configuration_Options::ACCOUNT_SUFFIX);
		$arrAuthenticatableSuffixes = NextADInt_Core_Util_StringUtil::trimmedExplode(';', $suffixes);

		// if $rawUsername does not contain no '@', than return the settings value for ACCOUNT_SUFFIX
		if (empty($suffix)) {
			return $arrAuthenticatableSuffixes;
		}

		if ($suffix[0] != '@') {
			$suffix = '@' . $suffix;
		}

		// return the new account suffix
		if (sizeof($arrAuthenticatableSuffixes) == 0) {
			// if no account suffix is set in the settings, then return the account suffix from $rawUsername
			$this->logger->debug("No account suffix set. Using user domain '{$suffix}' as account suffix.");

			return array($suffix);
		}

		if (($idx = array_search($suffix, $arrAuthenticatableSuffixes)) !== false) {
			// if the user uses one of the stored account suffixes, then return all stored $accountSuffixes
			$this->logger->debug("User domain '{$suffix}' is in list of account suffixes. Using this as first testable account suffix.");

			unset($arrAuthenticatableSuffixes[$idx]);
			array_unshift($arrAuthenticatableSuffixes, $suffix);
		}

		return $arrAuthenticatableSuffixes;
	}

	/**
	 * Execute the Active Directory authentication.
	 *
	 * The authentication fails if the user could not be found by his username, suffix and password or his account is blocked by brute-force attempts.
	 *
	 * @param string      $username
	 * @param string|null $accountSuffix
	 * @param string      $password
	 *
	 * @return false|string a string is returned if the authentication has been a success.
	 */
	public function authenticateAtActiveDirectory($username, $accountSuffix, $password)
	{
		// check if a socket to the domain controller(s) can be established. (Debugging)
		if (NextADInt_Core_Logger::equalLevel(LoggerLevel::getLevelDebug())) {
			$this->ldapConnection->checkPorts();
		}

		// LDAP_Connection
		$this->ldapConnection->connect(new NextADInt_Ldap_ConnectionDetails());

		// check if user has been blocked by previous failed attempts
		$this->bruteForceProtection($username);

		// try to authenticate the user with $username $accountSuffix and $password
		$success = $this->ldapConnection->authenticate($username, $accountSuffix, $password);

		// block or unblock user (depends on the authentication)
		$this->refreshBruteForceProtectionStatusForUser($username, $success);

		// check if user is now blocked or unblocked
		$this->bruteForceProtection($username);

		// stop if user could not be authenticated
		if (!$success) {
			$this->logger->error("User '$username' can not be authenticated.");

			return false;
		}

		return $this->isUserAuthorized($username, $accountSuffix);
	}

	/**
	 * @param $username
	 * @param $accountSuffix
	 *
	 * @return bool
	 */
	protected function isUserAuthorized($username, $accountSuffix)
	{
		// search for role mapping by the SAMAccountName and UserPrincipleName and merge them together
		$roleMapping = $this->roleManager->createRoleMapping($username);
		$upnRoleMapping = $this->roleManager->createRoleMapping($username . $accountSuffix);
		$roleMapping->merge($upnRoleMapping);

		// check if an user is in a authorization ad group if the user must be a member for login
		$authorizeByGroup = $this->configuration->getOptionValue(NextADInt_Adi_Configuration_Options::AUTHORIZE_BY_GROUP);

		if ($authorizeByGroup && !$this->roleManager->isInAuthorizationGroup($roleMapping)) {
			$this->logger->error("User '$username' is not in an authorization group.");

			return false;
		}

		return true;
	}

	/**
	 * Execute brute-force protection.
	 *
	 * If the user has been blocked, an e-mail is sent to the WordPress administrators.
	 *
	 * @param string $username
	 */
	function bruteForceProtection($username)
	{
		// if $this->mailNotification or $this->userBlockedMessage is null, then do not update the user
		if (!$this->userBlockedMessage || !$this->failedLogin) {
			$this->logger->warn(
				"Do not send a notification email and/or do not block the user because the user login is only simulated."
			);

			return;
		}

		// if brute force is disabled, then leave
        if ($this->configuration->getOptionValue(NextADInt_Adi_Configuration_Options::MAX_LOGIN_ATTEMPTS) === 0) {
            return;
        }

		// if user is not blocked, then leave
		if (!$this->failedLogin->isUserBlocked($username)) {
			return;
		}

		// ADI-383 Added default parameter useLocalWordPressUser to prevent get_userMeta request to AD if user credentials are wrong
		// send notification emails
		$this->mailNotification->sendNotifications($username, true);

		// log details
		$this->logger->error("Brute Force Alert: User '$username' has too many failed logins.");

		if (isset($_SERVER['REMOTE_ADDR'])) {
			$this->logger->error("REMOTE_ADDR: " . print_r($_SERVER['REMOTE_ADDR'], true));
		}

		if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			$this->logger->error("HTTP_X_FORWARDED_FOR: " . print_r($_SERVER['HTTP_X_FORWARDED_FOR'], true));
		}

		// show block screen and kill WordPress
		$this->userBlockedMessage->blockCurrentUser();
	}

	/**
	 * Block or unblock user.
	 *
	 * @param string  $username
	 * @param boolean $successfulLogin if true, the user is un-blocked; otherwise, he is blocked
	 */
	function refreshBruteForceProtectionStatusForUser($username, $successfulLogin)
	{
		if (!$this->failedLogin) {
			$this->logger->warn("Can not block or unblock the user because the user login is only simulated.");

			return;
		}

		// handle authenticated-status
		if ($successfulLogin) {
			$this->failedLogin->deleteLoginAttempts($username);
		} else {
			$this->failedLogin->increaseLoginAttempts($username);

			$totalAttempts = $this->failedLogin->findLoginAttempts($username);

			if ($totalAttempts > $this->configuration->getOptionValue(NextADInt_Adi_Configuration_Options::MAX_LOGIN_ATTEMPTS)) {
				$this->failedLogin->blockUser($username, $this->configuration->getOptionValue(NextADInt_Adi_Configuration_Options::BLOCK_TIME));
			}
		}
	}

	/**
	 * After authentication the user is created or updated.
	 * If his account is disabled he is not able to login.
	 *
	 * @param NextADInt_Adi_Authentication_Credentials $credentials
	 *
	 * @return bool false if user is disabled
	 * @access package
	 */
	function postAuthentication(NextADInt_Adi_Authentication_Credentials $credentials)
	{
		$wpUser = $this->createOrUpdateUser($credentials);

		// ADI-256: user does only have a valid id if he is already inside the directory or has been created with "Auto Create User" == on
		if (is_object($wpUser) && !is_wp_error($wpUser) && ($wpUser->ID > 0)) {
			if ($this->userManager->isDisabled($wpUser->ID)) {
				$this->logger->error("Unable to login user. User is disabled.");

				return false;
			}
		}

		return $wpUser;
	}

	/**
	 * This method updates or creates an user depending on the parameters.
	 * It internally delegates to createUser or updateUser.
	 *
	 * @param NextADInt_Adi_Authentication_Credentials $credentials
	 *
	 * @return false|int|WP_Error
	 */
	function createOrUpdateUser(NextADInt_Adi_Authentication_Credentials $credentials)
	{
		NextADInt_Core_Assert::notNull($credentials, "credentials must not be null");

		// ADI-204: during login we have to use the authenticated user principal name
		$ldapAttributes = $this->attributeService->findLdapAttributesOfUser($credentials, null);
		// update the real sAMAccountName of the credentials. This could be totally different from the userPrincipalName user for login
		$credentials->setSAMAccountName($ldapAttributes->getFilteredValue('samaccountname'));

		$adiUser = $this->userManager->createAdiUser($credentials, $ldapAttributes);

		// ADI-309: domain SID gets not synchronized
		$domainSid = $this->ldapConnection->getDomainSid();
		$adiUser->getLdapAttributes()->setDomainSid($domainSid);

		if ($adiUser->getId()) {
			$wpUser = $this->updateUser($adiUser);
		} else {
			$wpUser = $this->createUser($adiUser);
		}

		if (is_wp_error($wpUser)) {
			$this->logger->error("Unable to update or create '" . $adiUser . "': " . $wpUser->get_error_message());

			return $wpUser;
		}

		if (is_object($wpUser)) {
			$this->currentUserAuthenticated = true;
		}

		return $wpUser;
	}

	/**
	 * If "Auto Create User" is enabled, the user is created. If "Auto Create User" is disabled, it returns a WP_Error
	 *
	 *
	 * @param NextADInt_Adi_User $user
	 *
	 * @return false|int|WP_Error false if creation is only simulated; int if user has been created by underlying repository; WP_Error if autoCreateUser is disabled.
	 */
	public function createUser(NextADInt_Adi_User $user)
	{
		$this->logger->debug("Checking preconditions for creating new user " . $user);
		$autoCreateUser = $this->configuration->getOptionValue(NextADInt_Adi_Configuration_Options::AUTO_CREATE_USER);

		// ADI-117: The behavior changed with 2.0.x and has been agreed with CST on 2016-03-02.
		// In 1.0.x users were created even if auoCreateUser was false but they had a role equivalent group.
		// With 2.0.x the user is only created if the option "Auto Create User" is enabled.
		if (!$autoCreateUser) {
			$error = 'This user exists in Active Directory, but not in the local WordPress instance. The option "Auto Create User" is __not__ enabled but should be.';
			$this->logger->error($error);

			return new WP_Error(
				'invalid_username', __(
					$error,
					NEXT_AD_INT_I18N
				)
			);
		}

		// if $this->userManager is null, then do not create the user
		if (!$this->userManager) {
			$this->logger->warn(
				"User '{$user->getUsername()}' will not be created because the user login is only simulated."
			);

			return false;
		}

		// create user and return WP_User
		return $this->userManager->create($user);
	}

	/**
	 * If "Auto Update User" is enabled, the user's profile data is updated. In any case if a $userRole is present, it is synchronized with the backend.
	 *
	 * @param NextADInt_Adi_User $user
	 *
	 * @return false|WP_User false if creation is only simulated; int if user has been updated.
	 */
	function updateUser(NextADInt_Adi_User $user)
	{
		$this->logger->debug("Checking preconditions for updating existing user " . $user);

		$autoUpdateUser = $this->configuration->getOptionValue(NextADInt_Adi_Configuration_Options::AUTO_UPDATE_USER);
		$hasMappedWordPressRole = sizeof($user->getRoleMapping()) > 0;

		// ADI-116: The behavior changed with 2.0.x and has been agreed with CST on 2016-03-02.
		// In 1.0.x users were only updated if the options "Auto Create User" AND "Auto Update User" had been enabled.
		// With 2.0.x the option "Auto Update User" is only responsible for that.
		if ($autoUpdateUser) {
			// updateWordPressAccount already delegates to role update and updating of sAMAccountName
			return $this->userManager->update($user);
		}

		// in any case the sAMAccountName has to be updated
		$this->userManager->updateSAMAccountName($user->getId(), $user->getCredentials()->getSAMAccountName());

		if (!$hasMappedWordPressRole) {
			// prevent from removing any existing WordPress roles
			return false;
		}

		// if autoUpdateUser is disabled we still have to update his role
		$this->userManager->updateUserRoles($user->getId(), $user->getRoleMapping());

		// get WP_User from NextADInt_Adi_User
		return $this->userManager->findById($user->getId());
	}

	/**
	 * Get a WP_User instance for the user with login $login. Any user who is disabled will not be retrieved
	 *
	 * @param string $login Username or e-mail address
	 *
	 * @return false|WP_User
	 */
	public function getWordPressUser($login)
	{
		// get user id
		$userId = username_exists($login);

		if (!$userId) {
			$this->logger->debug("User '$login' could not be found with requested username.");

			return false;
		}

		$this->logger->debug("User '$login' has ID '$userId'.");

		return new WP_User($userId);
	}

	/**
	 * Return whether the current user is authenticated or not
	 *
	 * @return bool
	 */
	public function isCurrentUserAuthenticated()
	{
		return $this->currentUserAuthenticated;
	}

	/**
	 * @return NextADInt_Adi_Authentication_Persistence_FailedLoginRepository
	 */
	public function getFailedLogin()
	{
		return $this->failedLogin;
	}

	/**
	 * @return NextADInt_Ldap_Connection
	 */
	public function getLdapConnection()
	{
		return $this->ldapConnection;
	}

	/**
	 * @return NextADInt_Multisite_Configuration_Service
	 */
	public function getConfiguration()
	{
		return $this->configuration;
	}

	/**
	 * @return NextADInt_Adi_User_Manager
	 */
	public function getUserManager()
	{
		return $this->userManager;
	}

	/**
	 * @return NextADInt_Adi_Mail_Notification
	 */
	public function getMailNotification()
	{
		return $this->mailNotification;
	}

	/**
	 * @return NextADInt_Adi_Authentication_Ui_ShowBlockedMessage
	 */
	public function getUserBlockedMessage()
	{
		return $this->userBlockedMessage;
	}

	/**
	 * @return NextADInt_Ldap_Attribute_Service
	 */
	public function getAttributeService()
	{
		return $this->attributeService;
	}

	/**
	 * @return Logger
	 */
	public function getLogger()
	{
		return $this->logger;
	}

	/**
	 * @return NextADInt_Adi_Role_Manager
	 */
	public function getRoleManager()
	{
		return $this->roleManager;
	}
}