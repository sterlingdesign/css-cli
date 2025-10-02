<?php
declare(strict_types=1);
namespace Sterling\StackTools;

class UserCommandTask extends ThreadWithUnbufferedChannel
{
  //-----------------------------------------------------------------------------------------------
  protected function getEntryClosure(): \Closure
  {
    return \Closure::fromCallable([UserCommandTask::class, '_entry']);
    //return \Sterling\StackTools\UserCommandTask::_entry(...);
  }
  //-----------------------------------------------------------------------------------------------
  protected function getRuntimeBootstrap(): ?string
  {
    return BOOTSTRAP_AUTOLOAD_PHP;
  }
  //-----------------------------------------------------------------------------------------------
  // Notes:
  //
  // Unfortunately, there doesn't seem to be any way to "peek" on the
  // php://stdin stream.  Tried stream_get_contents, stream_set_timeout, stream_select, and
  // stream_set_blocking: none of them work with a stream on a pipe on Windows
  //
  // If we could "peek" to see if characters are ready for reading, it would make
  // this process much smoother.  Notably, if this thread is shut down
  // for reasons other than user command entry, the user will have to
  // press the enter key before the calling script will fully exit...
  // even calling kill() on the Runtime does not end it.
  protected static function _entry(\parallel\Channel $channel): int
  {
    CliInfo("User Command task is starting...");
    $hStdin = fopen("php://stdin", "r");
    // we need to catch the event when the channel is closed
    $oEvents = new \parallel\Events();
    $oEvents->setBlocking(false);
    $oEvents->addChannel($channel);

    try
      {
       while(is_resource($hStdin) && self::_test_continue($oEvents, $channel))
        {
        // fgets blocks until it returns
        $cmd = trim(strval(fgets($hStdin)));
        if(self::_test_continue($oEvents, $channel))
          {
          // The channel should be a Blocking channel
          $channel->send($cmd);
          // wait for the master threat to tell me to go or stop
          if("go" !== $channel->recv()) break;
          }
        }
      }
    catch(\Throwable $throwable)
      {
      CliError($throwable->getMessage());
      }
    CliInfo("User Command task is ending.");
    if(is_resource($hStdin))
      fclose($hStdin);
    return 0;
  }
}