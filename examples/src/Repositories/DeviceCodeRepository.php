<?php
/**
 * @author    Andrew Millington <andrew@noexceptions.io>
 * @copyright Copyright (c) Alex Bilbie
 * @license   http://mit-license.org/
 *
 * @link      https://github.com/thephpleague/oauth2-server
 */

namespace OAuth2ServerExamples\Repositories;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\DeviceCodeEntityInterface;
use League\OAuth2\Server\Repositories\DeviceCodeRepositoryInterface;
use OAuth2ServerExamples\Entities\DeviceCodeEntity;

class DeviceCodeRepository implements DeviceCodeRepositoryInterface
{
    /**
     * {@inheritDoc}
     */
    public function getNewDeviceCode()
    {
        return new DeviceCodeEntity();
    }

    /**
     * {@inheritDoc}
     */
    public function persistNewDeviceCode(DeviceCodeEntityInterface $deviceCodeEntity)
    {
        // Some logic to persist a new device code to a database
    }

    /**
     * {@inheritDoc}
     */
    public function getDeviceCodeEntityByDeviceCode($deviceCode, $grantType, ClientEntityInterface $clientEntity)
    {
        $deviceCode = new DeviceCodeEntity();

        // The user identifier should be set when the user authenticates on the OAuth server
        $deviceCode->setUserIdentifier(1);

        return $deviceCode;
    }

    /**
     * {@inheritDoc}
     */
    public function revokeDeviceCode($codeId)
    {
        // Some logic to revoke device code
    }

    /**
     * {@inheritDoc}
     */
    public function isDeviceCodeRevoked($codeId)
    {
        // Some logic to check if a device code has been revoked
    }
}