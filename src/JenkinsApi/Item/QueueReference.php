<?php

namespace JenkinsApi\Item;

use JenkinsApi\AbstractItem;
use JenkinsApi\Jenkins;

class QueueReference extends AbstractItem
{
    /**
     * @var Jenkins
     */
    protected $_jenkins;

    /**
     * @var string
     */
    protected $_queueItem;

    /**
     * @param Jenkins $jenkins
     * @param string $queueItem
     */
    public function __construct(Jenkins $jenkins, $queueItem)
    {
        $this->_jenkins = $jenkins;
        $this->_queueItem = $queueItem;
    }

    /**
     * @return $this
     */
    public function refresh()
    {
        $this->_data = $this->getJenkins()->get($this->getUrl(), 0);
        return $this;
    }

    /**
     * @return string
     */
    public function getUrl()
    {
        $url = parse_url($this->_queueItem);
        return $url['path'] . 'api/json';
    }

}
