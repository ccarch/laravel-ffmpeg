<?php

namespace Pbmedia\LaravelFFMpeg;

use Closure;
use Evenement\EventEmitterInterface;
use FFMpeg\Format\FormatInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Traits\ForwardsCalls;
use Pbmedia\LaravelFFMpeg\Drivers\PHPFFMpeg;
use Pbmedia\LaravelFFMpeg\FFMpeg\AdvancedOutputMapping;
use Pbmedia\LaravelFFMpeg\Filesystem\Disk;
use Pbmedia\LaravelFFMpeg\Filesystem\Media;

class MediaExporter
{
    use ForwardsCalls;

    protected PHPFFMpeg $driver;
    private ?FormatInterface $format = null;
    protected Collection $maps;
    protected ?string $visibility        = null;
    private ?Disk $toDisk                = null;
    private ?Closure $onProgressCallback = null;
    private ?float $lastPercentage       = null;
    private ?float $timelapseFramerate   = null;
    private bool $concatWithTranscoding  = false;
    private bool $concatWithVideo        = false;
    private bool $concatWithAudio        = false;

    public function __construct(PHPFFMpeg $driver)
    {
        $this->driver = $driver;

        $this->maps = new Collection;
    }

    protected function getDisk(): Disk
    {
        if ($this->toDisk) {
            return $this->toDisk;
        }

        $media = $this->driver->getMediaCollection();

        return $this->toDisk = $media->first()->getDisk();
    }

    public function inFormat(FormatInterface $format): self
    {
        $this->format = $format;

        return $this;
    }

    public function onProgress(Closure $callback): self
    {
        $this->onProgressCallback = $callback;

        return $this;
    }

    public function addFormatOutputMapping(FormatInterface $format, Media $output, array $outs, $forceDisableAudio = false, $forceDisableVideo = false)
    {
        $this->maps->push(
            new AdvancedOutputMapping($outs, $format, $output, $forceDisableAudio, $forceDisableVideo)
        );

        return $this;
    }

    public function toDisk($disk)
    {
        $this->toDisk = Disk::make($disk);

        return $this;
    }

    public function withVisibility(string $visibility)
    {
        $this->visibility = $visibility;

        return $this;
    }

    public function getCommand(string $path = null)
    {
        $this->driver->getPendingComplexFilters()->each->apply($this->driver, $this->maps);

        $this->maps->each->apply($this->driver->get());

        return $this->driver->getFinalCommand(
            $this->format,
            $path ? $this->getDisk()->makeMedia($path)->getLocalPath() : null
        );
    }

    private function applyProgressListenerToFormat(EventEmitterInterface $format)
    {
        $format->on('progress', function ($video, $format, $percentage) {
            if ($percentage !== $this->lastPercentage && $percentage < 100) {
                $this->lastPercentage = $percentage;
                call_user_func($this->onProgressCallback, $percentage);
            }
        });
    }

    public function asTimelapseWithFramerate(float $framerate): self
    {
        $this->timelapseFramerate = $framerate;

        return $this;
    }

    public function concatWithTranscoding($hasVideo = true, $hasAudio = true): self
    {
        $this->concatWithTranscoding = true;
        $this->concatWithVideo       = $hasVideo;
        $this->concatWithAudio       = $hasAudio;

        return $this;
    }

    public function save(string $path = null): MediaOpener
    {
        $outputMedia = $path ? $this->getDisk()->makeMedia($path) : null;

        if ($this->concatWithTranscoding) {
            $sources = $this->driver->getMediaCollection()->collection()->map(function ($media, $key) {
                return "[{$key}]";
            });

            $concatWithVideo = $this->concatWithVideo ? 1 : 0;
            $concatWithAudio = $this->concatWithAudio ? 1 : 0;

            $this->addFilter(
                $sources->implode(''),
                "concat=n={$sources->count()}:v={$concatWithVideo}:a={$concatWithAudio}",
                '[concat]'
            )->addFormatOutputMapping($this->format, $outputMedia, ['[concat]']);
        }

        if ($this->maps->isNotEmpty()) {
            return $this->saveWithMappings();
        }

        if ($this->format && $this->onProgressCallback) {
            $this->applyProgressListenerToFormat($this->format);
        }

        if ($this->timelapseFramerate > 0) {
            $this->format->setInitialParameters(array_merge(
                $this->format->getInitialParameters() ?: [],
                ['-framerate', $this->timelapseFramerate, '-f', 'image2']
            ));
        }

        if ($this->driver->isConcat()) {
            $this->driver->saveFromSameCodecs($outputMedia->getLocalPath());
        } elseif ($this->driver->isFrame()) {
            $this->driver->save($outputMedia->getLocalPath());
        } else {
            $this->driver->save($this->format, $outputMedia->getLocalPath());
        }

        $outputMedia->copyAllFromTemporaryDirectory($this->visibility);
        $outputMedia->setVisibility($this->visibility);

        if ($this->onProgressCallback) {
            call_user_func($this->onProgressCallback, 100);
        }

        return $this->getMediaOpener();
    }

    private function saveWithMappings(): MediaOpener
    {
        $this->driver->getPendingComplexFilters()->each->apply($this->driver, $this->maps);

        $this->maps->map->apply($this->driver->get());

        if ($this->onProgressCallback) {
            $this->applyProgressListenerToFormat($this->maps->last()->getFormat());
        }

        $this->driver->save();

        if ($this->onProgressCallback) {
            call_user_func($this->onProgressCallback, 100);
        }

        $this->maps->map->getOutputMedia()->each->copyAllFromTemporaryDirectory($this->visibility);

        return $this->getMediaOpener();
    }

    protected function getMediaOpener(): MediaOpener
    {
        return new MediaOpener(
            $this->driver->getMediaCollection()->last()->getDisk(),
            $this->driver->fresh(),
            $this->driver->getMediaCollection()
        );
    }

    protected function getEmptyMediaOpener($disk = null): MediaOpener
    {
        return new MediaOpener(
            $disk,
            $this->driver->fresh(),
        );
    }

    /**
     * Forwards the call to the driver object and returns the result
     * if it's something different than the driver object itself.
     */
    public function __call($method, $arguments)
    {
        $result = $this->forwardCallTo($driver = $this->driver, $method, $arguments);

        return ($result === $driver) ? $this : $result;
    }
}
