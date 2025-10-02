<?php
declare(strict_types=1);
namespace Sterling\StackTools;

class DartProcessor extends ThreadWithUnbufferedChannel
{
//-------------------------------------------------------------------------------------------------
protected function getEntryClosure(): \Closure
{
  return \Closure::fromCallable([DartProcessor::class, '_entry']);
}
//-------------------------------------------------------------------------------------------------
protected function getRuntimeBootstrap(): ?string
{
  return BOOTSTRAP_AUTOLOAD_PHP;
}
//-------------------------------------------------------------------------------------------------
private static function _entry(\parallel\Channel $channel, string $strArgs, bool $bNoSourceMaps) : int
{
  //CliInfo("DartProcessor is starting...");
  $resDartProc = null;
  $iResult = -1;
  $pipes = [];
  //$stdin = fopen('php://stdin', 'r');
  $stdout = fopen('php://stdout', 'w');
  $stderr = fopen('php://stderr', 'w');
  $arDescriptors = [
    0 => array('pipe', 'r'), // STDIN
    1 => $stdout, // STDOUT
    2 => $stderr  // STDERR
  ];

  try
    {
    $arStatus = [];
    $iResult = 0;
    $cmd = \Sterling\StackTools\DartProcessor::GetDartCmd(true, true, $bNoSourceMaps) . $strArgs;
    $resDartProc = proc_open($cmd, $arDescriptors, $pipes);
    if(!is_resource($resDartProc) || (($arStatus = proc_get_status($resDartProc)) === false))
      {
      CliError("Failed to start the sass child process");
      return -1;
      }
    CliInfo("Sass Process started [pid=" . $arStatus['pid'] . "]");

    $events = new \parallel\Events();
    $events->addChannel($channel);
    $events->setBlocking(false); // don't block on Events::poll()

    while($arStatus['running'] && self::_test_continue($events, $channel))
      {
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
    CliInfo("Sass Watcher process is ending.");
    // Send CTRL-C over the pipe to signal dart to terminate
    // Unfortunately, this doesn't work to stop dart either: fwrite(pipes[0], "\x03")
    //fwrite($pipes[0], "\x03");
    //posix_kill($arStatus['pid'], SIGTERM); // doesn't work
    sdKillProcess($resDartProc, $pipes, true);
    }
  else
    {
    $iResult = -1;
    CliError("Sass Watcher is ending because the child process terminated unexpectedly");
    // mainly need to close the pipes to free resources
    sdProcessClose($resDartProc, $pipes, true, 0);
    }

  return $iResult;
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