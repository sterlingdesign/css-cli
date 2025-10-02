<?php
declare(strict_types=1);
namespace Sterling\StackTools;

use parallel\Channel;

abstract class ThreadWithUnbufferedChannel extends SimpleParallelThread
{
  protected ?Channel $m_oChannel = null;
  protected string $m_strChannelName = '';

  //-------------------------------------------------------------------------------------------------
  public function __construct()
  {
    parent::__construct();
    $this->m_strChannelName = get_class($this) . '-Channel-' . sdRandomString(8);
    $this->m_oChannel = Channel::make($this->m_strChannelName);
  }
  //-------------------------------------------------------------------------------------------------
  public function GetChannelName() : string
  {
    return $this->m_strChannelName;
  }
  //-------------------------------------------------------------------------------------------------
  public function GetChannel() : Channel
  {
    return $this->m_oChannel;
  }
}