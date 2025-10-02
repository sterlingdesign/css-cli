<?php
declare(strict_types=1);
namespace Sterling\StackTools;

use parallel\Sync\Error;

class SassDirectories
{
  protected $m_arDirectoryPairs = [];
//-------------------------------------------------------------------------------------------------
public function __construct(array $arDirectoryPairs = array())
{
  foreach($arDirectoryPairs as $arPair)
    {
    if(is_array($arPair) && isset($arPair['input']) && isset($arPair['output']) && !$this->HasSassDirectory($arPair['input']))
      array_push($this->m_arDirectoryPairs, $arPair);
    }
}
//-------------------------------------------------------------------------------------------------
public function AddDirectories(array $arPairs) : bool
{
  $bOk = true;
  foreach($arPairs as $strPair)
    {
    if(!$this->AddDirectoryPair($strPair))
      $bOk = false;
    }
  return $bOk;
}
//-------------------------------------------------------------------------------------------------
public function AddDirectoryPair(string $strPair) : bool
{
  $arPair = explode(':', $strPair);
  $bOk = false;

  if(is_array($arPair) && count($arPair) > 2 && IsWindowsOS())
    {
    // Handle when a full path is specified for either or both directories:
    // "C:\my\path:D:\other\path"
    // "\my\path:D:\other\path"
    // or "x:\removable\path:relative\path"
    $arPairFixed = array();
    for($i = 0; $i < count($arPair); $i++)
      {
      if(preg_match('/^[a-zA-Z]$/', trim($arPair[$i])))
        {
        $fixed = trim($arPair[$i]) . ":";
        $i++;
        if($i < count($arPair))
          $fixed .= $arPair[$i];
        array_push($arPairFixed, $fixed);
        }
      else
        array_push($arPairFixed, $arPair[$i]);
      }
    $arPair = $arPairFixed;
    }

  if(!is_array($arPair) || count($arPair) !== 2)
    CliError("Invalid sass input/output directory pair: \"$strPair\"");
  else
    $bOk = $this->AddSourceAndTargetDirs(strval($arPair[0]), strval($arPair[1]));
  return $bOk;
}
//-------------------------------------------------------------------------------------------------
public function AddSourceAndTargetDirs(string $strSassDir, string $strOutputDir) : bool
{
  $bOk = false;
  $strRealSassDir = realpath($strSassDir);
  $strRealOutputDir = realpath($strOutputDir);
  if(false === $strRealSassDir)
    {
    CliError("The specified sass input directory does not exist: \"{$strSassDir}\"");
    }
  else if(false === $strRealOutputDir)
    {
    CliError("The specified output directory does not exist: \"{$strOutputDir}\"");
    }
  else
    {
    $bOk = true;
    if($this->HasSassDirectory($strRealSassDir))
      {
      CliWarning("Ignoring duplicate sass input directory \"{$strSassDir}\"");
      }
    else
      {
      if($strRealOutputDir === $strRealSassDir)
        CliWarning("The specified sass input and output directories are the same: \"{$strRealOutputDir}\"");
      $arPair = array();
      $arPair['input'] = $strRealSassDir;
      $arPair['output'] = $strRealOutputDir;
      array_push($this->m_arDirectoryPairs, $arPair);
      }
    }
  return $bOk;
}
//-------------------------------------------------------------------------------------------------
public function HasSassDirectory(string $strSassFolder) : bool
{
  foreach($this->m_arDirectoryPairs as $arPair)
    {
    if(IsWindowsOS())
      {
      if(strcasecmp($arPair['input'], $strSassFolder) === 0)
        return true;
      }
    else if(strcmp($arPair['input'], $strSassFolder) == 0)
      return true;
    }
  return false;
}
//-------------------------------------------------------------------------------------------------
public function AddStackDirs(array $arStackDirs) : int
{
  $iTotalAdded = 0;
  foreach($arStackDirs as $strStackTop)
    {
    $strRealStackTop = realpath($strStackTop);
    if(is_string($strRealStackTop) && is_dir($strRealStackTop))
      {
      CliInfo("Scanning {$strRealStackTop} for standard sass folders...");
      $iCountAdded = $this->addStackSassPairs($strRealStackTop, true);
      if($iCountAdded <= 0)
        CliWarning("No valid sass input/output directories exist in the stack specified by \"{$strRealStackTop}\"");
      else
        $iTotalAdded += $iCountAdded;
      }
    }
  return $iTotalAdded;
}
//-------------------------------------------------------------------------------------------------
protected function addStackSassPairs(string $strDir, bool $bDecend) : int
{
  $iCount = 0;
  $oDir = dir($strDir);
  while(false !== ($d = $oDir->read()))
    {
    if(($d == ".") || ($d == "..")) continue;
    // The names returned from $oDir->read are not absolute, they are relative to the $strDir supplied in constructor
    $d = sdMakeFilePath($strDir, $d);
    if(is_dir($d))
      {
      $strSassDir = sdMakeFilePath($d, "sass");
      $strOutputDir = sdMakeFilePath($d, "public/style");
      if(is_dir($strSassDir))
        {
        if(is_dir($strOutputDir))
          {
          if($this->AddSourceAndTargetDirs($strSassDir, $strOutputDir))
            $iCount++;
          }
        else
          CliWarning("A Sass Input directory exists, but the standard output directory does not exist: {$strOutputDir}");
        }
      if($bDecend && is_dir(sdMakeFilePath($d, "hosts")))
        {
        $iCount += $this->addStackSassPairs(sdMakeFilePath($d, "hosts"), false);
        }
      }
    }
  return $iCount;
}
//-------------------------------------------------------------------------------------------------
public function GetSassDirCount() : int
{
  return count($this->m_arDirectoryPairs);
}
//-------------------------------------------------------------------------------------------------
public function GetDartSassDiretoryList() : string
{
  $strDirs = "";
  foreach($this->m_arDirectoryPairs as $arPair)
    $strDirs .= " \"{$arPair['input']}:{$arPair['output']}\"";
  return $strDirs;
}
//-------------------------------------------------------------------------------------------------
public function GetAllSassDirs() : array
{
  $arDirs = array();
  foreach($this->m_arDirectoryPairs as $arPair)
    array_push($arDirs, $arPair['input']);
  return $arDirs;
}
//-------------------------------------------------------------------------------------------------
public function GetAllDirectoryPairs() : array
{
  return $this->m_arDirectoryPairs;
}
//-------------------------------------------------------------------------------------------------
public function GetModifiedFiles() : array
{
  $arMod = array();
  // WOW, file times are cached??
  clearstatcache();
  for($i = 0; $i < count($this->m_arDirectoryPairs); $i++)
    {
    $arFiles = $this->scanInputForExpectedOutput($this->m_arDirectoryPairs[$i]['input'], '');
    foreach($arFiles as $strBaseName)
      {
      $arFile = [];
      $arFile['basename'] = $strBaseName;
      $arFile['full-path'] = sdMakeFilePath($this->m_arDirectoryPairs[$i]['output'], $strBaseName);
      $arFile['timestamp'] = (is_file($arFile['full-path']) ? filemtime($arFile['full-path']) : false);
      // search the existing list of files and compare the last saved modification time
      $bFound = false;
      for($j = 0; !$bFound && $j < count($this->m_arDirectoryPairs[$i]['files']); $j++)
        {
        if($arFile['basename'] === $this->m_arDirectoryPairs[$i]['files'][$j]['basename'])
          {
          $bFound = true;
          if($arFile['timestamp'] !== $this->m_arDirectoryPairs[$i]['files'][$j]['timestamp'])
            {
            if($this->m_arDirectoryPairs[$i]['files'][$j]['timestamp'] === false)
              $arFile['modification'] = 'created';
            else if($arFile['timestamp'] === false)
              $arFile['modification'] = 'deleted';
            else
              $arFile['modification'] = 'changed';
            // Update the cached timestamp
            $this->m_arDirectoryPairs[$i]['files'][$j]['timestamp'] = $arFile['timestamp'];
            array_push($arMod, $arFile);
            }
          }
        }
      // if the file wasn't found, it must be a new sass file
      if(!$bFound)
        {
        array_push($this->m_arDirectoryPairs[$i]['files'], $arFile);
        if($arFile['timestamp'] !== false)
          {
          $arFile['modification'] = 'created';
          array_push($arMod, $arFile);
          }
        }
      }
    }

  return $arMod;
}
//-------------------------------------------------------------------------------------------------
public function UpdateTimestamps(array $arFiles)
{
  clearstatcache();
  foreach($arFiles as $strFullPath)
    {
    if(!file_exists($strFullPath)) continue;
    if(IsWindowsOS())
      $strFullPath = str_replace('/','\\',$strFullPath);
    $pathonly = dirname($strFullPath);
    $bFound = false;
    for($i = 0; !$bFound && $i < count($this->m_arDirectoryPairs); $i++)
      {
      if($pathonly == $this->m_arDirectoryPairs[$i]['output'])
        {
        for($j = 0; !$bFound && $j < count($this->m_arDirectoryPairs[$i]['files']); $j++)
          {
          if($strFullPath == $this->m_arDirectoryPairs[$i]['files'][$j]['full-path'])
            {
            $bFound = true;
            $this->m_arDirectoryPairs[$i]['files'][$j]['timestamp'] = (is_file($strFullPath) ? filemtime($strFullPath) : false);
            break;
            }
          }
        }
      }
    }
}
//-------------------------------------------------------------------------------------------------
public function InitializeOutputStats()
{

  // for each sass directory, get a list of expected output files
  for($i = 0; $i < count($this->m_arDirectoryPairs); $i++)
    {
    $this->m_arDirectoryPairs[$i]['files'] = [];
    $arFiles = $this->scanInputForExpectedOutput($this->m_arDirectoryPairs[$i]['input'], '');
    foreach($arFiles as $strBaseName)
      {
      $arFile = [];
      $arFile['basename'] = $strBaseName;
      $arFile['full-path'] = sdMakeFilePath($this->m_arDirectoryPairs[$i]['output'], $strBaseName);
      if(IsWindowsOS())
        $arFile['full-path'] = str_replace('/', '\\', $arFile['full-path']);
      $arFile['timestamp'] = (is_file($arFile['full-path']) ? filemtime($arFile['full-path']) : false);
      array_push($this->m_arDirectoryPairs[$i]['files'], $arFile);
      }
    }
}
//-------------------------------------------------------------------------------------------------
  function getAllExpectedOutputFiles() : array
  {
    $arAllOutput = array();
    // get a list of all sass directories
    $arPairs = $this->GetAllDirectoryPairs();
    // for each sass directory, get a list of expected output files
    foreach($arPairs as $arPair)
      {
      $arAllOutput = array_merge($arAllOutput, $this->scanInputForExpectedOutput($arPair['input'], $arPair['output']));
      }
    return $arAllOutput;
  }
//-------------------------------------------------------------------------------------------------
  protected function scanInputForExpectedOutput(string $strInputDir, string $strOutputDir) :array
  {
    $arExpected = array();
    $oDir = dir($strInputDir);
    while(false !== ($item = $oDir->read()))
      {
      if(("."==$item)||(".."==$item)||is_dir($item)||(false===strpos($item, '.'))||($item[0] == '_')) continue;
      $arParts = explode('.',$item);
      $ext = array_pop($arParts);
      if( (strcmp($ext, "scss") == 0) || (strcmp($ext, "sass") == 0) )
        {
        array_push($arParts, "css");
        if(strlen($strOutputDir))
          array_push($arExpected, sdMakeFilePath($strOutputDir, implode('.', $arParts)));
        else
          array_push($arExpected, implode('.', $arParts));
        }
      }

    return $arExpected;
  }
//-------------------------------------------------------------------------------------------------
  static function buildDirectories(mixed $arOperands, mixed $arStackDirs) : SassDirectories
  {
    $oSassDirs = new SassDirectories();
    // the AddDirectories method displays errors as they occur and returns false
    // if a command line directory doesn't exist, we will consider that a fatal error

    if(is_array($arOperands) && count($arOperands) > 0)
      {
      CliInfo("Adding " . count($arOperands) . " standalone (non-stack) directories");
      if(!$oSassDirs->AddDirectories($arOperands))
        CliWarning("Some of the specified standalone (non-stack) directories could not be added.");
      }
    else
      {
      CliInfo("No Standalone (non-stack) Directories Specified");
      }

    if(is_array($arStackDirs) && count($arStackDirs) > 0)
      {
      CliInfo("Adding " . count($arStackDirs) . " Stack Directories");
      if(0 == $oSassDirs->AddStackDirs($arStackDirs))
        CliWarning("Stack directories were specified, but no valid sass sub folders were found");
      }
    else
      {
      CliInfo("No Stack Directories Specified");
      }
    return $oSassDirs;
  }

}