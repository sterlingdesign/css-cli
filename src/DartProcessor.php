<?php
declare(strict_types=1);
namespace Sterling\StackTools;

use parallel\{Channel, Error, Events, Events\Event\Type, Future, Runtime};

class DartProcessor extends SimpleParallelThread
{
//-------------------------------------------------------------------------------------------------
protected function getEntryClosure(): \Closure
{
  return \Closure::fromCallable('static::_entry');
}
//-------------------------------------------------------------------------------------------------
protected function getRuntimeBootstrap(): ?string
{
  return BOOTSTRAP_AUTOLOAD_PHP;
}
//-------------------------------------------------------------------------------------------------
public function GetFutureName(): string
{
  return "DartProcessorTask";
}
//-------------------------------------------------------------------------------------------------
private static function _entry(Channel $channel, string $strArgs, bool $bNoSourceMaps) : int
{
  CliInfo("DartProcessor is starting...");
  $resDartProc = null;
  $iResult = -1;
  $pipes = [];

  try
    {
    $cmd = \Sterling\StackTools\DartProcessor::GetDartCmd(true, true, $bNoSourceMaps) . $strArgs;
    $resDartProc = proc_open($cmd, array(
      0 => array('pipe', 'r'), // STDIN
      1 => array('pipe', 'w'), // STDOUT
      2 => array('pipe', 'w')  // STDERR
    ), $pipes);
    }
  catch(\Throwable $throwable)
    {
    CliError($throwable->getMessage());
    }

  if(!is_resource($resDartProc))
    {
    CliError("Failed to start the dart child process");
    return -1;
    }

  try
    {
    $iResult = 0;
    $arStatus = proc_get_status($resDartProc);
    $events = new Events();
    $events->addChannel($channel);
    $events->setBlocking(false); // don't block on Events::poll()
    while($arStatus['running'])
      {
      if(DartProcessor::_test_close($events, $channel))
        break;
      usleep(250000);
      $arStatus = proc_get_status($resDartProc);
      }
    }
  catch(\Throwable $throwable)
    {
    CliError($throwable->getMessage());
    $iResult = -1;
    }

  if($arStatus['running'])
    {
    CliInfo("DartProcessor is ending.");
    // Send CTRL-C over the pipe to signal dart to terminate
    // Unfortunately, this doesn't work to stop dart either: fwrite(pipes[0], "\x03")
    sdProcessClose($resDartProc, $pipes, true, 0);
    }
  else
    {
    $iResult = -1;
    CliError("DartProcessor is ending because the dart child process terminated unexpectedly");
    sdProcessClose($resDartProc, $pipes, false, 0);
    }

  return $iResult;
}
//-------------------------------------------------------------------------------------------------
protected static function _test_close(Events $oEvents, Channel $channel) : bool
{
  $bClose = false;
  if($event = $oEvents->poll())
    {
    if($event->type == Type::Close)
      $bClose = true;
    else
      $oEvents->addChannel($channel);
    }
  return $bClose;
}
//-------------------------------------------------------------------------------------------------
public static function GetDartCmd(bool $bWatch = true, bool $bExpanded = true, bool $bNoSourceMap = true) : string
{
  if(IsWindowsOS())
    $cmd = "sass.bat";
  else
    $cmd = "sass";

  // dart sass generates source maps by default unless the --no-source-map option is specified
  if($bNoSourceMap)
    $cmd .= " --no-source-map";

  if($bExpanded)
    $cmd .= " --style=expanded";
  else
    $cmd .= " --style=compressed";

  if($bWatch)
    $cmd .= " --watch";

  //"sass.bat --no-source-map --style=expanded --watch";
  return $cmd;
}
}