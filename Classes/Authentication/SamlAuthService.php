<?php

declare(strict_types=1);

/*
 * This file is part of the Extension "md_saml" for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * (c) 2022 Christoph Daecke <typo3@mediadreams.org>
 */

namespace Mediadreams\MdSaml\Authentication;

use Mediadreams\MdSaml\Event\ChangeUserEvent;
use Mediadreams\MdSaml\Service\SettingsService;
use OneLogin\Saml2\Auth;
use OneLogin\Saml2\Error;
use OneLogin\Saml2\Utils;
use OneLogin\Saml2\ValidationError;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Authentication\AbstractAuthenticationService;
use TYPO3\CMS\Core\Crypto\PasswordHashing\InvalidPasswordHashException;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\DefaultRestrictionContainer;
use TYPO3\CMS\Core\Database\Query\Restriction\PageIdListRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface;
use TYPO3\CMS\Core\Http\PropagateResponseException;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Log\Logger;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class SamlAuthService extends AbstractAuthenticationService
{
    /**
     * @var int
     */
    public const SUCCESS_BREAK = 200;

    /**
     * @var int
     */
    public const FAIL_CONTINUE = 100;

    /**
     * @var int
     */
    public const SUCCESS_CONTINUE = 10;

    /**
     * @var int
     */
    public const FAIL_BREAK = 0;

    protected SettingsService $settingsService;

    private readonly EventDispatcherInterface $eventDispatcher;

    public function __construct()
    {
        /** @var SettingsService $settingsService */
        $this->settingsService = GeneralUtility::makeInstance(SettingsService::class);
        $this->eventDispatcher = GeneralUtility::makeInstance(EventDispatcherInterface::class);
    }

    /**
     * Authenticate the user using the data from the ADFS
     * Decide, if the given $user is allowed to access or not
     *
     * @param array $user
     * @return int
     */
    public function authUser(array $user): int
    {
        $this->logger->debug(
            'SAML authentification: ' . __METHOD__ . ' begin'
        );

        if (!$this->inCharge()) {
            $this->logger->debug(
                'SAML authentification: not in charge.'
            );
            return self::FAIL_CONTINUE;
        }

        $loginType = $this->pObj->loginType;

        if (empty($user['username'])) {
            $errorMessage = $loginType . " Login-attempt from %s (%s), username '%s',"
                . ' SSO authentication failed (ext:md_saml)!';
            $this->writelog(
                255,
                3,
                3,
                1,
                $errorMessage,
                [
                    $this->authInfo['REMOTE_ADDR'],
                    $this->authInfo['REMOTE_HOST'],
                    $this->login['uname'],
                ]
            );
            $errorMessage = $loginType . ": Login-attempt from {REMOTE_ADDR} ({REMOTE_HOST}), username '{uname}}',"
                . ' SSO authentication failed (ext:md_saml)!';
            $this->logger->info(
                $errorMessage,
                [
                    'REMOTE_ADDR' => $this->authInfo['REMOTE_ADDR'],
                    'REMOTE_HOST' => $this->authInfo['REMOTE_HOST'],
                    'uname' => $this->login['uname'],
                ]
            );
            return self::FAIL_BREAK;
        }

        return self::SUCCESS_BREAK;
    }

    /**
     * Check, whether this AuthService should be used
     *
     * @return bool
     */
    protected function inCharge(): bool
    {
        $this->logger->debug(
            'SAML authentification: ' . __METHOD__ . ' begin'
        );

        if ($this->settingsService->useFrontendAssertionConsumerServiceAuto($_SERVER['REQUEST_URI'])) {
            return true;
        }

        return ($_REQUEST['login-provider'] ?? '') === 'md_saml'
            && ($this->pObj->loginType === 'BE' || $this->pObj->loginType === 'FE')
            && isset($this->login['status'])
            && $this->login['status'] === 'login';
    }

    /**
     * creates the PidRestriction for a given table and pid
     * @param int $pid
     * @param string $table
     * @return QueryRestrictionContainerInterface
     */
    protected function getDatabasePidRestriction(int $pid, string $table): QueryRestrictionContainerInterface {
            $restrictionContainer = GeneralUtility::makeInstance(DefaultRestrictionContainer::class);
            $restrictionContainer->add(
                GeneralUtility::makeInstance(
                    PageIdListRestriction::class,
                    [$table],
                    [$pid]
                )
            );
            return $restrictionContainer;
    }

    /**
     * Extends fetchUserRecord to respects the configured fe_user pid.
     *
     * @param $username
     * @param $extraWhere
     * @param $dbUserSetup
     * @return false|mixed[]
     */
    public function fetchUserRecord($username, $extraWhere = '', $dbUserSetup = '')
    {
        $dbUser = is_array($dbUserSetup) ? $dbUserSetup : $this->db_user;

        $loginType = $this->pObj->loginType;

        $extSettings = $this->settingsService->getSettings($loginType);

        if ($loginType === 'FE' && isset($extSettings['fe_users']['databaseDefaults']['pid'])) {
            $pid = (int)$extSettings['fe_users']['databaseDefaults']['pid'];
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('fe_users');
            $expressionBuilder = $queryBuilder->expr();
            $dbUser['enable_clause'] = (string) $this->getDatabasePidRestriction($pid, 'fe_users')->buildExpression(
                ['fe_users' => 'fe_users'],
                $expressionBuilder
            );
        }

        return parent::fetchUserRecord($username, $extraWhere, $dbUser);
    }

    /**
     * Get user data
     * Is called to get additional information after login.
     *
     * @return array|false|void
     * @throws Error
     * @throws InvalidPasswordHashException
     * @throws ValidationError
     * @throws PropagateResponseException
     */
    public function getUser()
    {
        $this->logger->debug(
            'SAML authentification: ' . __METHOD__ . ' begin'
        );

        if (!$this->inCharge()) {
            $this->logger->debug(
                'SAML authentification: not in charge.'
            );
            return false;
        }

        $loginType = $this->pObj->loginType;

        $extSettings = $this->settingsService->getSettings($loginType);
        if (!$extSettings) {
            $this->logger->error('No TypoScript plugin.tx_mdsaml.settings configured. Perhaps you did not include the md_saml static include.');
            return false;
        }

        if (
            isset($_REQUEST['acs'])
            || $this->settingsService->useFrontendAssertionConsumerServiceAuto($_SERVER['REQUEST_URI'])
        ) {
            $auth = new Auth($extSettings['saml']);
            $auth->processResponse();

            $errors = $auth->getErrors();

            if ($errors !== []) {
                $errorMessage = $loginType . ' Login-attempt from %s (%s) failed (ext:md_saml). SAML error: %s';
                $this->writelog(
                    255,
                    3,
                    3,
                    1,
                    $errorMessage,
                    [
                        $this->authInfo['REMOTE_ADDR'],
                        $this->authInfo['REMOTE_HOST'],
                        implode(', ', $errors),
                    ]
                );

                $errorMessage = $loginType . ': Login-attempt from {REMOTE_ADDR} ({REMOTE_HOST}) failed (ext:md_saml). '
                    . 'SAML error: {errors}:' . chr(10) . '{errorDetails}';
                $this->logger->error(
                    $errorMessage,
                    [
                        'REMOTE_ADDR' => $this->authInfo['REMOTE_ADDR'],
                        'REMOTE_HOST' => $this->authInfo['REMOTE_HOST'],
                        'errors' => implode(', ', $errors),
                        'errorDetails' => $auth->getLastErrorReason(),
                    ]
                );

                if ($auth->getSettings()->isDebugActive()) {
                    echo '<h1>SAML error</h1>';
                    echo '<p>' . implode(', ', $errors) . '</p>';
                    echo '<p>' . htmlentities((string) $auth->getLastErrorReason(), ENT_QUOTES | ENT_HTML5) . '</p>';
                    $this->logger->debug(
                        'SAML authentification: ' . __METHOD__ . ' EXIT in line ' . __LINE__
                    );
                    exit;
                }

                if (isset($_POST['RelayState']) && Utils::getSelfURL() !== $_POST['RelayState']) {
                    // To avoid 'Open Redirect' attacks, before execute the
                    // redirection confirm the value of $_POST['RelayState'] is a // trusted URL.
                    //$auth->redirectTo($_POST['RelayState']);
                    $url = GeneralUtility::getIndpEnv('TYPO3_SITE_URL')
                        . \TYPO3_MAINDIR
                        . '?loginProvider=1648123062&error=1';
                    throw new PropagateResponseException(new RedirectResponse($url, 303), 1706128564);
                }

                return false;
            }

            $samlAttributes = $auth->getAttributes();
            $user = $this->getUserArrayForDb($samlAttributes, $extSettings);
            $record = $this->fetchUserRecord($user['username']);
            if (is_array($record)) {
                if (
                    isset($extSettings[$this->authInfo['db_user']['table']]['updateIfExist']) &&
                    (int)$extSettings[$this->authInfo['db_user']['table']]['updateIfExist'] === 1
                ) {
                    $this->logger->debug(
                        "Record for user '{username}' found and will be updated.",
                        [
                            'username' => $user['username'],
                        ]
                    );
                    return $this->updateUser($record, $user);
                }

                $this->logger->debug(
                    "Record for user '{username}'  found. Will *not* be updated due to configuration.",
                    [
                        'username' => $user['username'],
                    ]
                );

                return $record;
            }

            if ((int)$extSettings[$this->authInfo['db_user']['table']]['createIfNotExist'] === 1) {
                $this->logger->debug(
                    "*No* record for user  '{username}'  found, but will be created.",
                    [
                        'username' => $user['username'],
                    ]
                );
                return $this->createUser($user);
            }

            $this->logger->debug(
                "Record for user  '{username}'  not found. Will *not* be created due to configuration.",
                [
                    'username' => $user['username'],
                ]
            );
        } else {
            $auth = new Auth($extSettings['saml']);
            $auth->login();
            $this->logger->debug(
                'SAML authentification has been processed.'
            );
        }

        $this->logger->debug(
            'SAML authentification could not authenticate this user.'
        );
        return false;
    }

    /**
     * Get user data as array for database
     *
     * @param array $samlAttributes
     * @param array $extSettings
     * @return array
     */
    protected function getUserArrayForDb(array $samlAttributes, array $extSettings): array
    {
        $this->logger->debug(
            'SAML authentification: ' . __METHOD__ . ' begin'
        );

        $userArr = [];
        $userArr['md_saml_source'] = 1;
        $transformationArr = array_flip($extSettings[$this->authInfo['db_user']['table']]['transformationArr']);

        // Add default values from TypoScript settings to user array
        foreach ($extSettings[$this->authInfo['db_user']['table']]['databaseDefaults'] as $key => $val) {
            $key = trim((string) $key);
            $val = trim((string) $val);

            if ($val !== '') {
                $userArr[$key] = $val;
            }
        }

        // Add values from SSO provider
        foreach ($samlAttributes as $attributeName => $attributeValues) {
            if (isset($transformationArr[$attributeName])) {
                $userArr[$transformationArr[$attributeName]] = $attributeValues[0];
            }
        }

        return $userArr;
    }

    /**
     * Update a existing frontend/backend user with given data
     *
     * @param array $localUser
     * @param array $userData
     * @return array|false
     */
    private function updateUser(array $localUser, array $userData)
    {
        $this->logger->debug(
            'SAML authentification: ' . __METHOD__ . ' begin'
        );

        $changed = false;
        $uid = $localUser['uid'] ?? 0;

        $userData = $this->eventDispatcher->dispatch(
            new ChangeUserEvent($userData)
        )->getUserData();

        foreach ($userData as $key => $value) {
            if ($localUser[$key] != $value) {
                $changed = true;
                break;
            }
        }

        if (!$changed || $uid === 0 || empty($userData['username'])) {
            return $localUser;
        }

        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable($this->authInfo['db_user']['table']);

        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction());

        $queryBuilder->update($this->authInfo['db_user']['table']);
        foreach ($userData as $key => $value) {
            $queryBuilder->set($key, $value);
        }

        $queryBuilder
            ->set('tstamp', time())
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT))
            )
            ->executeStatement();

        return $this->fetchUserRecord($userData['username']);
    }

    /**
     * Create a new backend user with given data
     *
     * @param array $userData
     * @return array|false
     * @throws InvalidPasswordHashException
     */
    protected function createUser(array $userData)
    {
        $this->logger->debug(
            'SAML authentification: ' . __METHOD__ . ' begin'
        );

        $saltingInstance = GeneralUtility::makeInstance(PasswordHashFactory::class)
            ->getDefaultHashInstance($this->authInfo['loginType']);

        if (!empty($userData['username'])) {
            $userArr = [
                'password' => $saltingInstance->getHashedPassword(md5(uniqid('', true))),
                'crdate' => time(),
                'tstamp' => time(),
                'disable' => 0,
                'starttime' => 0,
                'endtime' => 0,
            ];

            // This will add all information, which was received from SSO
            foreach ($userData as $key => $value) {
                $userArr[$key] = $value;
            }

            $userArr = $this->eventDispatcher->dispatch(
                new ChangeUserEvent($userArr)
            )->getUserData();

            /** @var QueryBuilder $queryBuilder */
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getQueryBuilderForTable($this->authInfo['db_user']['table']);

            $queryBuilder->getRestrictions()
                ->removeAll()
                ->add(new DeletedRestriction());

            $queryBuilder->insert($this->authInfo['db_user']['table'])
                ->values($userArr)
                ->executeStatement();

            return $this->fetchUserRecord($userData['username']);
        }

        return false;
    }
}
