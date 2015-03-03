<?php

namespace Detail\Auth\Identity\Adapter;

use ThreeScaleAuthorizeResponse;
use ThreeScaleClient;
use ThreeScaleServerError;

use Zend\Http\Request as HttpRequest;

use Detail\Auth\Identity\Exception;
use Detail\Auth\Identity\Identity;
use Detail\Auth\Identity\Result;

class ThreeScaleAdapter implements
    AdapterInterface
{
    const HEADER_APPLICATION_ID  = 'DWS-App-ID';
    const HEADER_APPLICATION_KEY = 'DWS-App-Key';

    /**
     * @var ThreeScaleClient
     */
    protected $client;

    /**
     * @var string
     */
    protected $serviceId;

    /**
     * @var boolean
     */
    protected $usePlanAsRole;

        /**
     * @var HttpRequest
     */
    protected $request;

    /**
     * @param ThreeScaleClient $client
     * @param string $serviceId
     * @param boolean $usePlanAsRole
     */
    public function __construct(ThreeScaleClient $client, $serviceId, $usePlanAsRole = true)
    {
        $this->setClient($client);
        $this->setServiceId($serviceId);
        $this->setUsePlanAsRole($usePlanAsRole);
    }

    /**
     * @return ThreeScaleClient
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * @param ThreeScaleClient $client
     */
    public function setClient($client)
    {
        $this->client = $client;
    }

    /**
     * @return string
     */
    public function getServiceId()
    {
        return $this->serviceId;
    }

    /**
     * @param string $serviceId
     */
    public function setServiceId($serviceId)
    {
        $this->serviceId = $serviceId;
    }

    /**
     * @return HttpRequest
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     * @param HttpRequest $request
     */
    public function setRequest(HttpRequest $request)
    {
        $this->request = $request;
    }

    /**
     * @return Result
     */
    public function authenticate()
    {
        $request = $this->getRequest();

        if ($request === null) {
            throw new Exception\RuntimeException(
                sprintf('Request object must be set before calling %s()', __METHOD__)
            );
        }

        $appId  = $request->getHeader(self::HEADER_APPLICATION_ID);
        $appKey = $request->getHeader(self::HEADER_APPLICATION_KEY);

        $messages = array();

        if (!$appId) {
            $messages[] = sprintf(
                'Missing application identifier; provide one using the "%s" header',
                self::HEADER_APPLICATION_ID
            );
        }

        if (!$appKey) {
            $messages[] = sprintf(
                'Missing application key; provide one using the "%s" header',
                self::HEADER_APPLICATION_KEY
            );
        }

        if (count($messages) > 0) {
            return new Result(false, null, $messages);
        }

        $usage = array('hits' => 1);
        $client = $this->getClient();

        try {
            $response = @$client->authorize(
                $appId->getFieldValue(),
                $appKey->getFieldValue(),
                $this->getServiceId(),
                $usage
            );
        } catch (ThreeScaleServerError $e) {
            throw new Exception\AuthenticationUnavailableException(
                sprintf(
                    'Failed to authenticate because 3scale seems to be unavailable: %s',
                    $e->getMessage()
                ),
                0,
                $e
            );
        } catch (\Exception $e) {
            throw new Exception\AuthenticationFailedException(
                sprintf(
                    'Failed to authenticate: %s',
                    $e->getMessage()
                ),
                0,
                $e
            );
        }



        /** @todo Use MvcEvent listener to log calls in background (using an IronMQ queue) */

        $messages = array();
        $plan = null;
        $identity = null;

        if (!$response->isSuccess()) {
            $messages[(string) $response->getErrorCode()] = $response->getErrorMessage();
        }

        if ($response instanceof ThreeScaleAuthorizeResponse) {
            $plan = $response->getPlan();

            if ($this->usePlanAsRole()) {
                $identity = new Identity('3scale-' . $plan);
            }
        }

        /** @todo Actually authenticate */
        return new Result($response->isSuccess(), $identity, $messages);
    }

    /**
     * @return boolean
     */
    public function usePlanAsRole()
    {
        return $this->usePlanAsRole;
    }

    /**
     * @param boolean $usePlanAsRole
     */
    public function setUsePlanAsRole($usePlanAsRole)
    {
        $this->usePlanAsRole = $usePlanAsRole;
    }
}
