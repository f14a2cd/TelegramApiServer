<?php

namespace TelegramApiServer;

use Amp\Loop;
use Amp\Promise;
use danog\MadelineProto;
use danog\MadelineProto\MTProto;
use InvalidArgumentException;
use Psr\Log\LogLevel;
use RuntimeException;
use TelegramApiServer\EventObservers\EventHandler;
use TelegramApiServer\EventObservers\EventObserver;
use function Amp\call;

class Client
{
    public static Client $self;
    /** @var MadelineProto\API[] */
    public array $instances = [];

    public static function getInstance(): Client {
        if (empty(static::$self)) {
            static::$self = new static();
        }
        return static::$self;
    }

    private static function isSessionLoggedIn(MadelineProto\API $instance): bool
    {
        return ($instance->API->authorized ?? MTProto::NOT_LOGGED_IN) === MTProto::LOGGED_IN;
    }

    public function connect(array $sessionFiles): void
    {
        warning(PHP_EOL . 'Starting MadelineProto...' . PHP_EOL);

        foreach ($sessionFiles as $file) {
            $sessionName = Files::getSessionName($file);
            $instance = $this->addSession($sessionName);
            $this->runSession($instance);
        }

        $this->startSessions();

        $sessionsCount = count($sessionFiles);
        warning(
            "\nTelegramApiServer ready."
            . "\nNumber of sessions: {$sessionsCount}."
        );
    }

    public function addSession(string $session, array $settings = []): MadelineProto\API
    {
        if (isset($this->instances[$session])) {
            throw new InvalidArgumentException('Session already exists');
        }
        $file = Files::getSessionFile($session);
        Files::checkOrCreateSessionFolder($file);
        $settings = array_replace_recursive((array) Config::getInstance()->get('telegram'), $settings);
        $instance = new MadelineProto\API($file, $settings);
        $instance->async(true);

        $this->instances[$session] = $instance;
        return $instance;
    }

    public function removeSession($session): void
    {
        if (empty($this->instances[$session])) {
            throw new InvalidArgumentException('Session not found');
        }

        EventObserver::stopEventHandler($session);
        $this->instances[$session]->stop();

        /** @see runSession() */
        //Mark this session as not logged in, so no other actions will be made.
        $this->instances[$session]->API->authorized = MTProto::NOT_LOGGED_IN;

        unset($this->instances[$session]);
    }

    /**
     * @param string|null $session
     *
     * @return MadelineProto\API
     */
    public function getSession(?string $session = null): MadelineProto\API
    {
        if (!$this->instances) {
            throw new RuntimeException(
                'No sessions available. Call /system/addSession?session=%session_name% or restart server with --session option'
            );
        }

        if (!$session) {
            if (count($this->instances) === 1) {
                $session = (string) array_key_first($this->instances);
            } else {
                throw new InvalidArgumentException(
                    'Multiple sessions detected. Specify which session to use. See README for examples.'
                );
            }
        }

        if (empty($this->instances[$session])) {
            throw new InvalidArgumentException('Session not found.');
        }

        return $this->instances[$session];
    }

    private function startSessions(): Promise
    {
        return call(
            function() {
                foreach ($this->instances as $instance) {
                    if (!static::isSessionLoggedIn($instance)) {
                        $this->loop(
                            $instance,
                            static function() use ($instance) {
                                //Disable logging to stdout
                                $logLevel = Logger::getInstance()->minLevelIndex;
                                Logger::getInstance()->minLevelIndex = Logger::$levels[LogLevel::EMERGENCY];

                                yield $instance->start();

                                //Enable logging to stdout
                                Logger::getInstance()->minLevelIndex = $logLevel;
                            }
                        );
                        $this->runSession($instance);
                    }
                }
            }
        );
    }

    public function runSession(MadelineProto\API $instance): Promise
    {
        return call(
            function() use ($instance) {
                if (static::isSessionLoggedIn($instance)) {
                    yield $instance->start();
                    Loop::defer(fn() => $this->loop($instance));
                }
            }
        );
    }

    private function loop(MadelineProto\API $instance, callable $callback = null): void
    {
        $sessionName = Files::getSessionName($instance->session);
        $this->getSessionState($instance);
        try {
            $callback ? $instance->loop($callback) : $instance->loop();
        } catch (\Throwable $e) {
            critical(
                $e->getMessage(),
                [
                    'probable_session' => $sessionName,
                    'exception' => Logger::getExceptionAsArray($e),
                ]
            );

        }
    }
    public function getSessionState(MadelineProto\API $instance): bool {
        warning("Checking session {$instance->session}");
        $instance->async(false);
        try {
            $test = $instance->users->getUsers(["id"=>[777000]]);
        } catch (\danog\MadelineProto\RPCErrorException $e) {
            critical("Something went wrong on session {$instance->session}. Deleting him..");
            $this->removeSession($instance->session);
            return false;
        }
        $instance->async(true);
        critical("Checking {$instance->session} finished without errors.");
        return true;        
    }
    public function getBrokenSessions(): array
    {
        $brokenSessions = [];
        foreach ($this->instances as $session => $instance) {
            warning("Checking session: {$session}");
            try {
                $instance->getSelf(['async' => false]);
            } catch (\Throwable $e) {
                warning("Session is broken: {$session}");
                $brokenSessions[] = $session;
            }
        }

        return $brokenSessions;
    }

}
