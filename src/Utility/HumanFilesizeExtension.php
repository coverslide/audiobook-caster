<?php

namespace Coverslide\AudiobookCaster\Utility;

class HumanFilesizeExtension extends \Twig_Extension
{
    public function getFilters()
    {
        return array(
            new \Twig_SimpleFilter('human_filesize', array($this, 'getHumanFilesize'))
        );
    }

    public function getHumanFilesize($size, $unit = null)
    {
        if( (!$unit && $size >= 1<<30) || $unit == "GB")
            return number_format($size/(1<<30),2)."GB";
        if( (!$unit && $size >= 1<<20) || $unit == "MB")
            return number_format($size/(1<<20),2)."MB";
        if( (!$unit && $size >= 1<<10) || $unit == "KB")
            return number_format($size/(1<<10),2)."KB";
        return number_format($size)." bytes";
    }

    public function getName()
    {
        return 'human_filesize';
    }
}
