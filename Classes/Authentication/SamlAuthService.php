<?php
declare(strict_types=1);

namespace Mediadreams\MdSaml\Authentication;

/**
 *
 * This file is part of the Extension "md_saml" for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * (c) 2022 Christoph Daecke <typo3@mediadreams.org>
 *
 */

use Mediadreams\MdSaml\Service\SettingsService;
use OneLogin\Saml2\Auth;
use OneLogin\Saml2\Utils;
use TYPO3\CMS\Core\Authentication\AbstractAuthenticationService;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class SamlAuthService extends AbstractAuthenticationService
{
    const SUCCESS_BREAK = 200;
    const FAIL_CONTINUE = 100;
    const SUCCESS_CONTINUE = 10;
    const FAIL_BREAK = 0;

    /**
     * Authenticate the user using the data from the ADFS
     * Decide, if the given $user is allowed to access or not
     *
     * @param array $user
     * @return int
     */
    public function authUser(array $user): int
    {
        if (!$this->inCharge()) {
            return SELF::FAIL_CONTINUE;
        }

        $loginType = $this->pObj->loginType;

        if (empty($user['username'])) {
            $errorMessage = $loginType . ' Login-attempt from %s (%s), username \'%s\', SSO authentication failed (ext:md_saml)!';
            $this->writelog(
                255,
                3,
                3,
                1,
                $errorMessage,
                [
                    $this->authInfo['REMOTE_ADDR'],
                    $this->authInfo['REMOTE_HOST'],
                    $this->login['uname']
                ]
            );

            return SELF::FAIL_BREAK;
        } else {
            return SELF::SUCCESS_BREAK;
        }
    }

    /**
     * Get user data
     * Is called to get additional information after login.
     *
     * @return bool|mixed
     */
    public function getUser()
    {
        if (!$this->inCharge()) {
            return false;
        }

        $loginType = $this->pObj->loginType;

        /** @var SettingsService $settingsService */
        $settingsService = GeneralUtility::makeInstance(SettingsService::class);
        $extSettings = $settingsService->getSettings($loginType);

        if ($loginType == 'FE' && isset($extSettings['fe_users']['databaseDefaults']['pid'])) {
            $this->db_user['check_pid_clause'] = '`pid` IN (' . $extSettings['fe_users']['databaseDefaults']['pid'] . ')';
        }

        if (null !== GeneralUtility::_GP('acs')) {
            $auth = new Auth($extSettings['saml']);
            $auth->processResponse();

            $errors = $auth->getErrors();

            if (!empty($errors)) {
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
                        implode(', ', $errors)
                    ]
                );

                if ($auth->getSettings()->isDebugActive()) {
                    echo '<h1>SAML error</h1>';
                    echo '<p>' . implode(', ', $errors) . '</p>';
                    echo '<p>' . htmlentities($auth->getLastErrorReason()) . '</p>';
                    exit;
                } else {
                    if (isset($_POST['RelayState']) && Utils::getSelfURL() != $_POST['RelayState']) {
                        // To avoid 'Open Redirect' attacks, before execute the
                        // redirection confirm the value of $_POST['RelayState'] is a // trusted URL.
                        //$auth->redirectTo($_POST['RelayState']);
                        $url = GeneralUtility::getIndpEnv('TYPO3_SITE_URL') . TYPO3_mainDir . '?loginProvider=1648123062&error=1';
                        \TYPO3\CMS\Core\Utility\HttpUtility::redirect($url);
                    }
                }

                return false;
            } else {
                $samlAttributes = $auth->getAttributes();
                $user = $this->getUserArrayForDb($samlAttributes, $extSettings);

                $record = $this->fetchUserRecord($user['username']);
                if (is_array($record)) {
                    return $record;
                } elseif ($extSettings[$this->authInfo['db_user']['table']]['createIfNotExist'] == 1) {
                    return $this->createUser($user);
                }
            }
        } else {
            $auth = new Auth($extSettings['saml']);
            $auth->login();
        }

        return false;
    }

    /**
     * Create a new backend user with given data
     *
     * @param array $userData
     * @return array|false
     */
    private function createUser(array $userData)
    {
        $saltingInstance = GeneralUtility::makeInstance(PasswordHashFactory::class)
            ->getDefaultHashInstance($this->authInfo['loginType']);

        if (!empty($userData['username'])) {
            $userArr = [
                'password' => $saltingInstance->getHashedPassword(md5(uniqid())),
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

            /** @var QueryBuilder $queryBuilder */
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getQueryBuilderForTable($this->authInfo['db_user']['table']);

            $queryBuilder->getRestrictions()
                ->removeAll()
                ->add(new DeletedRestriction());

            $queryBuilder->insert($this->authInfo['db_user']['table'])
                ->values($userArr)
                ->execute();

            return $this->fetchUserRecord($userData['username']);
        }

        return false;
    }

    /**
     * Get user data as array for database
     *
     * @param array $samlAttributes
     * @param array $extSettings
     * @return array
     */
    private function getUserArrayForDb(array $samlAttributes, array $extSettings): array
    {
        $userArr = [];
        $transformationArr = array_flip($extSettings[$this->authInfo['db_user']['table']]['transformationArr']);

        // Add default values from TypoScript settings to user array
        foreach ($extSettings[$this->authInfo['db_user']['table']]['databaseDefaults'] as $key => $val) {
            $key = trim($key);
            $val = trim($val);

            if (!empty($val)) {
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
     * Check, whether this AuthService should be used
     *
     * @return bool
     */
    private function inCharge(): bool
    {
        if (GeneralUtility::_GP('login-provider') === "md_saml" &&
            ($this->pObj->loginType === 'BE' || $this->pObj->loginType === 'FE') &&
            isset($this->login['status']) &&
            $this->login['status'] === 'login'
        ) {
            return true;
        }

        return false;
    }
}
