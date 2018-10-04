<?php
/**
 * @license see LICENSE
 */

namespace HeadlessChromium;

use HeadlessChromium\Communication\Connection;
use HeadlessChromium\Communication\Message;
use HeadlessChromium\Communication\Target;
use HeadlessChromium\Exception\CommunicationException;
use HeadlessChromium\Exception\NoResponseAvailable;
use HeadlessChromium\Exception\CommunicationException\ResponseHasError;
use HeadlessChromium\Exception\OperationTimedOut;

class Browser
{
    /**
     * @var Connection
     */
    protected $connection;

    /**
     * @var Target[]
     */
    protected $targets = [];

    /**
     * A preScript to be automatically added on every new pages
     * @var string|null
     */
    protected $pagePreScript;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;

        // listen for target created
        $this->connection->on(Connection::EVENT_TARGET_CREATED, function (array $params) {

            // create and store the target
            $this->targets[$params['targetInfo']['targetId']] = new Target($params['targetInfo'], $this->connection);
        });

        // listen for target info changed
        $this->connection->on(Connection::EVENT_TARGET_INFO_CHANGED, function (array $params) {

            // get target by id
            $target = $this->getTarget($params['targetInfo']['targetId']);

            if ($target) {
                $target->targetInfoChanged($params['targetInfo']);
            }
        });

        // listen for target destroyed
        $this->connection->on(Connection::EVENT_TARGET_DESTROYED, function (array $params) {

            // get target by id
            $target = $this->getTarget($params['targetId']);

            if ($target) {
                // remove the target
                unset($this->targets[$params['targetId']]);
                $target->destroy();
                $this->connection
                    ->getLogger()
                    ->debug('✘ target(' . $params['targetId'] . ') was destroyed and unreferenced.');
            }
        });
    }

    /**
     * @return Connection
     */
    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * Set a preScript to be added on every new pages.
     * Use null to disable it.
     *
     * @param string|null $script
     */
    public function setPagePreScript(string $script = null)
    {
        $this->pagePreScript = $script;
    }

    /**
     * Closes the browser
     */
    public function close()
    {
        // TODO
        throw new \Exception('Not implemented yet, see ProcessAwareBrowser instead');
    }

    /**
     * Creates a new page
     * @throws NoResponseAvailable
     * @throws CommunicationException
     * @throws OperationTimedOut
     * @return Page
     */
    public function createPage(): Page
    {

        // page url
        $params = ['url' => 'about:blank'];

        // create page and get target id
        $response = $this->connection->sendMessageSync(new Message('Target.createTarget', $params));
        $targetId = $response['result']['targetId'];

        // todo handle error

        $target = $this->getTarget($targetId);
        if (!$target) {
            throw new \RuntimeException('Target could not be created for page.');
        }

        // get initial frame tree
        $frameTreeResponse = $target->getSession()->sendMessageSync(new Message('Page.getFrameTree'));

        // make sure frame tree was found
        if (!$frameTreeResponse->isSuccessful()) {
            throw new ResponseHasError('Cannot read frame tree. Please, consider upgrading chrome version.');
        }

        // create page
        $page = new Page($target, $frameTreeResponse['result']['frameTree']);

        // Page.enable
        $page->getSession()->sendMessageSync(new Message('Page.enable'));

        // Network.enable
        $page->getSession()->sendMessageSync(new Message('Network.enable'));

        // Page.setLifecycleEventsEnabled
        $page->getSession()->sendMessageSync(new Message('Page.setLifecycleEventsEnabled', ['enabled' => true]));

        // add prescript
        if ($this->pagePreScript) {
            $page->addPreScript($this->pagePreScript);
        }

        return $page;
    }

    /**
     * @param $targetId
     * @return Target|null
     */
    public function getTarget($targetId)
    {
        // make sure target was created (via Target.targetCreated event)
        if (!array_key_exists($targetId, $this->targets)) {
            return null;
        }
        return $this->targets[$targetId];
    }

    /**
     * @return Target[]
     */
    public function getTargets()
    {
        return array_values($this->targets);
    }
}
