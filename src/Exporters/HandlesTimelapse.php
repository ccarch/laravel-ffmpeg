<?php

namespace Pbmedia\LaravelFFMpeg\Exporters;

trait HandlesTimelapse
{
    protected ?float $timelapseFramerate = null;

    public function asTimelapseWithFramerate(float $framerate): self
    {
        $this->timelapseFramerate = $framerate;

        return $this;
    }

    protected function addTimelapseParametersToFormat()
    {
        $this->format->setInitialParameters(array_merge(
            $this->format->getInitialParameters() ?: [],
            ['-framerate', $this->timelapseFramerate, '-f', 'image2']
        ));
    }
}