<?php

declare(strict_types=1);

/*
 * This file is part of the Sonata Project package.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sonata\MediaBundle\Provider;

use Gaufrette\Filesystem;
use Sonata\Form\Validator\ErrorElement;
use Sonata\MediaBundle\CDN\CDNInterface;
use Sonata\MediaBundle\Generator\GeneratorInterface;
use Sonata\MediaBundle\Model\MediaInterface;
use Sonata\MediaBundle\Resizer\ResizerInterface;
use Sonata\MediaBundle\Thumbnail\ThumbnailInterface;

abstract class BaseProvider implements MediaProviderInterface
{
    /**
     * @var array
     */
    protected $formats = [];

    /**
     * @var string[]
     */
    protected $templates = [];

    /**
     * @var ResizerInterface
     */
    protected $resizer;

    /**
     * @var Filesystem
     */
    protected $filesystem;

    /**
     * @var GeneratorInterface
     */
    protected $pathGenerator;

    /**
     * @var CDNInterface
     */
    protected $cdn;

    /**
     * @var ThumbnailInterface
     */
    protected $thumbnail;

    /**
     * @var string
     */
    protected $name;

    /**
     * @var MediaInterface[]
     */
    private $clones = [];

    /**
     * @param string $name
     */
    public function __construct($name, Filesystem $filesystem, CDNInterface $cdn, GeneratorInterface $pathGenerator, ThumbnailInterface $thumbnail)
    {
        $this->name = $name;
        $this->filesystem = $filesystem;
        $this->cdn = $cdn;
        $this->pathGenerator = $pathGenerator;
        $this->thumbnail = $thumbnail;
    }

    final public function transform(MediaInterface $media): void
    {
        if (null === $media->getBinaryContent()) {
            return;
        }

        $this->doTransform($media);
        $this->flushCdn($media);
    }

    public function flushCdn(MediaInterface $media): void
    {
        if ($media->getId() && $this->requireThumbnails() && !$media->getCdnIsFlushable()) {
            $flushPaths = [];
            foreach ($this->getFormats() as $format => $settings) {
                if (MediaProviderInterface::FORMAT_ADMIN === $format ||
                    substr($format, 0, \strlen((string) $media->getContext())) === $media->getContext()) {
                    $flushPaths[] = $this->getFilesystem()->get($this->generatePrivateUrl($media, $format), true)->getKey();
                }
            }
            if (!empty($flushPaths)) {
                $cdnFlushIdentifier = $this->getCdn()->flushPaths($flushPaths);
                $media->setCdnFlushIdentifier($cdnFlushIdentifier);
                $media->setCdnIsFlushable(true);
                $media->setCdnStatus(CDNInterface::STATUS_TO_FLUSH);
            }
        }
    }

    public function addFormat($name, $format): void
    {
        $this->formats[$name] = $format;
    }

    public function getFormat($name)
    {
        return isset($this->formats[$name]) ? $this->formats[$name] : false;
    }

    public function requireThumbnails()
    {
        return null !== $this->getResizer();
    }

    public function generateThumbnails(MediaInterface $media): void
    {
        $this->thumbnail->generate($this, $media);
    }

    public function removeThumbnails(MediaInterface $media, $formats = null): void
    {
        $this->thumbnail->delete($this, $media, $formats);
    }

    public function getFormatName(MediaInterface $media, $format)
    {
        if (MediaProviderInterface::FORMAT_ADMIN === $format) {
            return MediaProviderInterface::FORMAT_ADMIN;
        }

        if (MediaProviderInterface::FORMAT_REFERENCE === $format) {
            return MediaProviderInterface::FORMAT_REFERENCE;
        }

        $baseName = $media->getContext().'_';
        if (substr($format, 0, \strlen($baseName)) === $baseName) {
            return $format;
        }

        return $baseName.$format;
    }

    public function getProviderMetadata()
    {
        return new Metadata($this->getName(), $this->getName().'.description', null, 'SonataMediaBundle', ['class' => 'fa fa-file']);
    }

    public function preRemove(MediaInterface $media): void
    {
        $hash = spl_object_hash($media);
        $this->clones[$hash] = clone $media;

        if ($this->requireThumbnails()) {
            $this->thumbnail->delete($this, $media);
        }
    }

    public function postRemove(MediaInterface $media): void
    {
        $hash = spl_object_hash($media);

        if (isset($this->clones[$hash])) {
            $media = $this->clones[$hash];
            unset($this->clones[$hash]);
        }

        $path = $this->getReferenceImage($media);

        if ($this->getFilesystem()->has($path)) {
            $this->getFilesystem()->delete($path);
        }
    }

    public function generatePath(MediaInterface $media)
    {
        return $this->pathGenerator->generatePath($media);
    }

    public function getFormats()
    {
        return $this->formats;
    }

    public function setName($name): void
    {
        $this->name = $name;
    }

    public function getName()
    {
        return $this->name;
    }

    public function setTemplates(array $templates): void
    {
        $this->templates = $templates;
    }

    public function getTemplates()
    {
        return $this->templates;
    }

    public function getTemplate($name)
    {
        return isset($this->templates[$name]) ? $this->templates[$name] : null;
    }

    public function getResizer()
    {
        return $this->resizer;
    }

    public function getFilesystem()
    {
        return $this->filesystem;
    }

    public function getCdn()
    {
        return $this->cdn;
    }

    public function getCdnPath($relativePath, $isFlushable)
    {
        return $this->getCdn()->getPath($relativePath, $isFlushable);
    }

    public function setResizer(ResizerInterface $resizer): void
    {
        $this->resizer = $resizer;
    }

    public function prePersist(MediaInterface $media): void
    {
        $media->setCreatedAt(new \DateTime());
        $media->setUpdatedAt(new \DateTime());
    }

    public function preUpdate(MediaInterface $media): void
    {
        $media->setUpdatedAt(new \DateTime());
    }

    public function validate(ErrorElement $errorElement, MediaInterface $media): void
    {
    }

    abstract protected function doTransform(MediaInterface $media);
}
