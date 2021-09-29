<?php
namespace JenkinsApi\Item;

use DOMDocument;
use JenkinsApi\AbstractItem;
use JenkinsApi\Exceptions\JenkinsApiException;
use JenkinsApi\Exceptions\JobNotFoundException;
use JenkinsApi\Exceptions\WaitTimeoutException;
use JenkinsApi\Jenkins;
use RuntimeException;

/**
 * @package    JenkinsApi\Item
 * @author     Christopher Biel <christopher.biel@jungheinrich.de>
 * @version    $Id$
 *
 * @method string getName()
 * @method string getColor()
 */
class Job extends AbstractItem
{
    /**
     * @var
     */
    private $_jobName;

    /**
     * @param         $jobName
     * @param Jenkins $jenkins
     */
    public function __construct($jobName, Jenkins $jenkins)
    {
        $this->_jobName = $jobName;
        $this->_jenkins = $jenkins;

        $this->refresh();
    }

    public function refresh()
    {
        try {
            return parent::refresh();
        } catch (JenkinsApiException $e) {
            throw new JobNotFoundException($this->_jobName, 0, $e);
        }
    }

    /**
     * @return string
     */
    protected function getUrl()
    {
        return sprintf('job/%s/api/json', rawurlencode($this->_jobName));
    }

    /**
     * @return Build[]
     */
    public function getBuilds()
    {
        $builds = array();
        foreach ($this->_data->builds as $build) {
            $builds[] = $this->getBuild($build->number);
        }

        return $builds;
    }

    /**
     * @param int $buildId
     *
     * @return Build
     * @throws RuntimeException
     */
    public function getBuild($buildId)
    {
        return $this->_jenkins->getBuild($this->getName(), $buildId);
    }

    /**
     * @return array
     */
    public function getParametersDefinition()
    {
        $parameters = array();

        foreach ($this->_data->actions as $action) {
            if (!property_exists($action, 'parameterDefinitions')) {
                continue;
            }

            foreach ($action->parameterDefinitions as $parameterDefinition) {
                $default = property_exists($parameterDefinition, 'defaultParameterValue') ? $parameterDefinition->defaultParameterValue->value : null;
                $description = property_exists($parameterDefinition, 'description') ? $parameterDefinition->description : null;
                $choices = property_exists($parameterDefinition, 'choices') ? $parameterDefinition->choices : null;

                $parameters[$parameterDefinition->name] = array('default' => $default, 'choices' => $choices, 'description' => $description);
            }
        }

        return $parameters;
    }

    /**
     * @return Build|null
     */
    public function getLastSuccessfulBuild()
    {
        if (null === $this->_data->lastSuccessfulBuild) {
            return null;
        }

        return $this->_jenkins->getBuild($this->getName(), $this->_data->lastSuccessfulBuild->number);
    }

    /**
     * @return Build|null
     */
    public function getLastBuild()
    {
        if (null === $this->_data->lastBuild) {
            return null;
        }
        return $this->_jenkins->getBuild($this->getName(), $this->_data->lastBuild->number);
    }

    /**
     * @return bool
     */
    public function isCurrentlyBuilding()
    {
        $lastBuild = $this->getLastBuild();
        if ($lastBuild === null) {
            return false;
        }
        return $lastBuild->isBuilding();
    }

    /**
     * @param array $parameters
     *
     * @return bool|resource
     */
    public function launch($parameters = array())
    {
        if (empty($parameters)) {
            return $this->_jenkins->post(sprintf('job/%s/build', rawurlencode($this->_jobName)));
        } else {
            return $this->_jenkins->post(sprintf('job/%s/buildWithParameters', rawurlencode($this->_jobName)), $parameters);
        }
    }

    /**
     * @param array $parameters
     *
     * @param int $timeoutSeconds
     * @param int $checkIntervalSeconds
     * @return bool|Build
     */
    public function launchAndWait($parameters = array(), $timeoutSeconds = 86400, $checkIntervalSeconds = 5)
    {
        if (!$this->isCurrentlyBuilding()) {
            $lastNumber = $this->getLastBuild()->getNumber();
            $startTime = time();
            $response = $this->launch($parameters);
            // TODO evaluate the response correctly, to get the queued item and later the build
            if ($response) {
//                list($header, $body) = explode("\r\n\r\n", $response, 2);
            }

            while ((time() < $startTime + $timeoutSeconds)
                && (($this->getLastBuild()->getNumber() == $lastNumber)
                    || ($this->getLastBuild()->getNumber() == $lastNumber + 1
                        && $this->getLastBuild()->isBuilding()))) {
                sleep($checkIntervalSeconds);
                $this->refresh();
            }
        } else {
            while ($this->getLastBuild()->isBuilding()) {
                sleep($checkIntervalSeconds);
                $this->refresh();
            }
        }
        return $this->getLastBuild();
    }

    public function delete()
    {
        if (!$this->getJenkins()->post(sprintf('job/%s/doDelete', $this->_jobName))) {
            throw new RuntimeException(sprintf('Error deleting job %s on %s', $this->_jobName, $this->getJenkins()->getBaseUrl()));
        }
    }

    public function getConfig()
    {
        $config = $this->getJenkins()->get(sprintf('job/%s/config.xml', $this->_jobName));
        if ($config) {
            throw new RuntimeException(sprintf('Error during getting configuation for job %s', $this->_jobName));
        }
        return $config;
    }

    /**
     * @param string $jobname
     * @param DomDocument $document
     *
     * @deprecated use setJobConfig instead
     */
    public function setConfigFromDomDocument($jobname, DomDocument $document)
    {
        $this->setJobConfig($jobname, $document->saveXML());
    }

    /**
     * @param string $configuration config XML
     *
     */
    public function setJobConfig($configuration)
    {
        $return = $this->getJenkins()->post(sprintf('job/%s/config.xml', $this->_jobName), $configuration, array(CURLOPT_HTTPHEADER => array('Content-Type: text/xml')));
        if ($return != 1) {
            throw new RuntimeException(sprintf('Error during setting configuration for job %s', $this->_jobName));
        }
    }

    public function disable()
    {
        if (!$this->getJenkins()->post(sprintf('job/%s/disable', $this->_jobName))) {
            throw new RuntimeException(sprintf('Error disabling job %s on %s', $this->_jobName, $this->getJenkins()->getBaseUrl()));
        }
    }

    public function enable()
    {
        if (!$this->getJenkins()->post(sprintf('job/%s/enable', $this->_jobName))) {
            throw new RuntimeException(sprintf('Error enabling job %s on %s', $this->_jobName, $this->getJenkins()->getBaseUrl()));
        }
    }

    /**
     * @return boolean
     */
    public function isBuildable()
    {
        return $this->_data->buildable;
    }

    public function __toString()
    {
        return $this->_jobName;
    }

    /**
     * @param array $parameters
     * @return QueueReference
     */
    public function build($parameters = array())
    {
        if (empty($parameters)) {
            return $this->_jenkins->postBuild(sprintf('job/%s/build', rawurlencode($this->_jobName)));
        } else {
            return $this->_jenkins->postBuild(sprintf('job/%s/buildWithParameters', rawurlencode($this->_jobName)), $parameters);
        }
    }

    /**
     * @param QueueReference $queueReference
     * @param int $timeoutSeconds
     * @param int $checkIntervalSeconds
     * @return Build
     * @throws WaitTimeoutException
     */
    public function wait(QueueReference $queueReference, $timeoutSeconds = 3600, $checkIntervalSeconds = 1)
    {
        $propertyName = 'executable';
        $queueReference->refresh();
        $startTime = time();
        while (!$queueReference->get($propertyName)) {
            if (time() > $startTime + $timeoutSeconds) {
                throw new WaitTimeoutException(sprintf('Error wait timeout job %s on %s', $this->_jobName, $queueReference->getUrl()));
            }
            sleep($checkIntervalSeconds);
            $queueReference->refresh();
        }
        $number = $queueReference->get($propertyName)->number;
        $build = $this->getBuild($number);
        while ($build->isBuilding()) {
            if (time() > $startTime + $timeoutSeconds) {
                throw new WaitTimeoutException(sprintf('Error building timeout job %s on %s', $this->_jobName, $queueReference->getUrl()));
            }
            sleep($checkIntervalSeconds);
        }
        return $this->getBuild($number);
    }

}
