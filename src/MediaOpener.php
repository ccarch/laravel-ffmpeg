<?php

namespace Pbmedia\LaravelFFMpeg;

use FFMpeg\Coordinate\TimeCode;
use FFMpeg\Media\AbstractMediaType;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Traits\ForwardsCalls;
use Pbmedia\LaravelFFMpeg\Drivers\PHPFFMpeg;
use Pbmedia\LaravelFFMpeg\Exporters\HLSExporter;
use Pbmedia\LaravelFFMpeg\Exporters\MediaExporter;
use Pbmedia\LaravelFFMpeg\Filesystem\Disk;
use Pbmedia\LaravelFFMpeg\Filesystem\Media;
use Pbmedia\LaravelFFMpeg\Filesystem\MediaCollection;
use Pbmedia\LaravelFFMpeg\Filesystem\TemporaryDirectories;

class MediaOpener
{
    use ForwardsCalls;

    private Disk $disk;
    private PHPFFMpeg $driver;
    private MediaCollection $collection;
    private ?TimeCode $timecode = null;

    public function __construct($disk = null, PHPFFMpeg $driver = null, MediaCollection $mediaCollection = null)
    {
        $this->disk = Disk::make($disk ?: config('filesystems.default'));

        $this->driver = $driver ?: app(PHPFFMpeg::class);

        $this->collection = $mediaCollection ?: new MediaCollection;
    }

    public function fromDisk($disk): self
    {
        $this->disk = Disk::make($disk);

        return $this;
    }

    public function fromFilesystem(Filesystem $filesystem): self
    {
        return $this->fromDisk($filesystem);
    }

    public function open($path): self
    {
        $paths = Arr::wrap($path);

        foreach ($paths as $path) {
            $this->collection->push(Media::make($this->disk, $path));
        }

        return $this;
    }

    public function get(): MediaCollection
    {
        return $this->collection;
    }

    public function getDriver(): PHPFFMpeg
    {
        return $this->driver->open($this->collection);
    }

    public function getAdvancedDriver(): PHPFFMpeg
    {
        return $this->driver->openAdvanced($this->collection);
    }

    public function getFrameFromString(string $timecode): self
    {
        return $this->getFrameFromTimecode(
            TimeCode::fromString($timecode)
        );
    }

    public function getFrameFromSeconds(float $quantity): self
    {
        return $this->getFrameFromTimecode(
            TimeCode::fromSeconds($quantity)
        );
    }

    public function getFrameFromTimecode(TimeCode $timecode): self
    {
        $this->timecode = $timecode;

        return $this;
    }

    public function export(): MediaExporter
    {
        return tap(new MediaExporter($this->getDriver()), function (MediaExporter $mediaExporter) {
            if ($this->timecode) {
                $mediaExporter->frame($this->timecode);
            }
        });
    }

    public function exportForHLS(): HLSExporter
    {
        return new HLSExporter($this->getAdvancedDriver());
    }

    public function cleanupTemporaryFiles(): self
    {
        TemporaryDirectories::deleteAll();

        return $this;
    }

    public function __invoke(): AbstractMediaType
    {
        return $this->getDriver()->get();
    }

    public function __call($method, $arguments)
    {
        $result = $this->forwardCallTo($driver = $this->getDriver(), $method, $arguments);

        return ($result === $driver) ? $this : $result;
    }
}