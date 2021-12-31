<?php
declare(strict_types=1);
namespace Sterling\StackTools;

use parallel\Channel;
use parallel\Future;
use parallel\Runtime;

abstract class SimpleParallelThread
{
  protected $m_oFuture = null;
  protected $m_oRuntime = null;
  protected $m_oChannel = null;
  protected $m_strChannelName = "";

  abstract protected function getEntryClosure() : \Closure;
  abstract protected function getRuntimeBootstrap() : ?string;
  abstract public function GetFutureName() : string;

//-------------------------------------------------------------------------------------------------
  public function __destruct()
  {
    if($this->IsRunning())
      $this->Stop(0);
  }
//-------------------------------------------------------------------------------------------------
  public function GetChannelName() : string
  {
    return $this->m_strChannelName;
  }
//-------------------------------------------------------------------------------------------------
  protected function MakeChannel() : Channel
  {
    $this->m_strChannelName = get_class($this) . sdRandomString(8);
    return Channel::make($this->m_strChannelName);
  }
//-------------------------------------------------------------------------------------------------
  public function GetChannel() : ?Channel
  {
    return $this->m_oChannel;
  }
//-------------------------------------------------------------------------------------------------
  public function GetFuture() : ?Future
  {
    return $this->m_oFuture;
  }
//-------------------------------------------------------------------------------------------------
  public function Start(array $arArgs = array()) : Future
  {
    if($this->IsRunning())
      {
      CliError("Process is already running");
      return $this->m_oFuture;
      }

    $this->m_oChannel = $this->MakeChannel();
    $arArgs = array_merge([$this->m_oChannel], $arArgs);
    $fnEntry = $this->getEntryClosure();
    $this->m_oRuntime = new Runtime($this->getRuntimeBootstrap());
    $this->m_oFuture = $this->m_oRuntime->run($fnEntry, $arArgs);
    return $this->m_oFuture;
  }
//-------------------------------------------------------------------------------------------------
  public function Stop(int $wait_seconds_before_kill = 2)
  {
    if(is_object($this->m_oChannel))
      $this->m_oChannel->close();
    $this->m_oChannel = null;
    $this->m_strChannelName = "";

    // wait up to $wait_seconds_before_kill for graceful exit of the running threads - 1 second = 1,000,000 microseconds
    $iSleepMicroSeconds = 10000;
    for($iWaitTime = 0; ($iWaitTime < $wait_seconds_before_kill * 1000000) && $this->IsRunning(); $iWaitTime+=$iSleepMicroSeconds)
      usleep($iSleepMicroSeconds);

    $value = null;
    // if it is still running, kill it via the runtime
    if($this->IsRunning())
      $this->Kill();
    else if(is_object($this->m_oFuture))
      $value = $this->m_oFuture->value();

    $this->m_oFuture = null;
    $this->m_oRuntime = null;
    return $value;
  }
//-------------------------------------------------------------------------------------------------
  protected function Kill()
  {
    try
      {
      // $this->m_oFuture->cancel - Shall try to cancel the task
      // Note: If task is running, it will be interrupted.
      // Warning: Internal function calls in progress cannot be interrupted.
      // Warning: Shall throw parallel\Future\Error\Killed if parallel\Runtime executing task was killed.
      // Warning: Shall throw parallel\Future\Error\Cancelled if task was already cancelled.
      if(is_object($this->m_oFuture))
        {
        if(!$this->m_oFuture->done())
          {
          if(is_object($this->m_oRuntime))
            $this->m_oRuntime->kill();
          else
            $this->m_oFuture->cancel();
          }
        }
      else if(is_object($this->m_oRuntime))
        $this->m_oRuntime->kill();
      }
    catch(\Throwable $throwable)
      {
      CliError($throwable->getMessage());
      }
  }
//-------------------------------------------------------------------------------------------------
  public function IsRunning() : bool
  {
    return (is_object($this->m_oFuture) && !$this->m_oFuture->done());
  }

}