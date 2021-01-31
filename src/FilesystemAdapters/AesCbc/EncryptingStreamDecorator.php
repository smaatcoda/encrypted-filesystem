<?php


namespace SmaatCoda\EncryptedFilesystem\FilesystemAdapters\AesCbc;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use LogicException;
use Psr\Http\Message\StreamInterface;
use SmaatCoda\EncryptedFilesystem\Interfaces\EncryptionMethodInterface;
use SmaatCoda\EncryptedFilesystem\Interfaces\RequiresIvContract;
use SmaatCoda\EncryptedFilesystem\Interfaces\RequiresPaddingContract;

class EncryptingStreamDecorator implements StreamInterface
{
    use StreamDecoratorTrait;

    protected $stream;

    /**
     * @var EncryptionMethodInterface
     */
    protected $encryptor;

    protected $buffer = '';

    public function __construct(StreamInterface $stream, EncryptionMethodInterface $encryptor)
    {
        $this->stream = $stream;
        $this->encryptor = $encryptor;
    }

    public function seek($offset, $whence = SEEK_SET)
    {
        if ($whence === SEEK_CUR) {
            $offset = $this->tell() + $offset;
            $whence = SEEK_SET;
        }
        if ($whence === SEEK_SET) {
            $this->buffer = '';
            $wholeBlockOffset = $this->encryptor->getBlockSize() * ceil($offset / $this->encryptor->getBlockSize());
            $this->encryptor->seek($wholeBlockOffset, $whence);
            $this->stream->seek($wholeBlockOffset, $whence);
            $this->read($offset - $wholeBlockOffset);
        } else {
            throw new LogicException('Unrecognized whence.');
        }
    }

    public function read($length)
    {
        if ($length > strlen($this->buffer)) {
//            while (strlen($this->buffer) < $length) {
                $plaintext = $this->stream->read(
                    $this->encryptor->getBlockSize() * ceil(($length - strlen($this->buffer)) / $this->encryptor->getBlockSize())
                );

                $this->buffer .= $this->encryptor->encrypt($plaintext, $this->eof());
//            }
        }

        $data = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length);
        return $data ?: '';
    }

    public function eof()
    {
        return $this->stream->eof() && empty($this->buffer);
    }

    public function getSize()
    {
        $filesize = $this->stream->getSize();

        if ($this->encryptor instanceof RequiresPaddingContract) {
            $filesize += $this->encryptor->getPaddingSize($filesize);
        }

        if ($this->encryptor instanceof RequiresIvContract) {
            $filesize += $this->encryptor->getIvSize();
        }

        return $filesize;
    }

    public function isWritable()
    {
        return false;
    }
}
