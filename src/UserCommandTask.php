<?php
declare(strict_types=1);
namespace Sterling\StackTools;

use parallel\Channel;
use parallel\Events;
use parallel\Events\Event\Type;
use parallel\Future;
use parallel\Runtime;

class UserCommandTask extends SimpleParallelThread
{
  //-----------------------------------------------------------------------------------------------
  public static function PrintMenu()
  {
    echo "Type 'q' or 'quit' and press Enter to quit " . PHP_EOL;
  }
  //-----------------------------------------------------------------------------------------------
  protected function getEntryClosure(): \Closure
  {
    return \Closure::fromCallable('static::_entry');
  }
  //-----------------------------------------------------------------------------------------------
  protected function getRuntimeBootstrap(): ?string
  {
    return BOOTSTRAP_AUTOLOAD_PHP;
  }
  //-----------------------------------------------------------------------------------------------
  public function GetFutureName(): string
  {
    return "UserCommandTask";
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
  protected static function _entry(Channel $channel)
  {
    CliInfo("User Command task is starting...");
    $hStdin = fopen("php://stdin", "r");
    $bContinue = true;
    $oEvents = new Events();
    $oEvents->setBlocking(false);
    $oEvents->addChannel($channel);

    try
      {
       while($bContinue && is_resource($hStdin))
        {
        UserCommandTask::PrintMenu();
        $cmd = trim(fgets($hStdin));
        $bContinue = self::_test_continue($oEvents, $channel);
        if($bContinue && strlen($cmd))
          {
          $channel->send($cmd);
          usleep(100000);
          $bContinue = self::_test_continue($oEvents, $channel);
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
  //-----------------------------------------------------------------------------------------------
  protected static function _test_continue(Events $oEvents, Channel $channel) : bool
  {
    $bContinue = true;
    if($oEvent = $oEvents->poll())
      {
      if($oEvent->type == Type::Close)
        $bContinue = false;
      else
        $oEvents->addChannel($channel);
      }
    return $bContinue;
  }
}