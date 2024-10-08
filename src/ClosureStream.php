<?php

    declare (strict_types = 1);

    namespace Coco\closure;

class ClosureStream
{
    const string STREAM_PROTO = 'closure';

    protected static bool $isRegistered = false;

    public $context;

    protected string $content;

    protected int $length;

    protected int $pointer = 0;

    function stream_open($path, $mode, $options, &$opened_path): bool
    {
        $this->content = "<?php\nreturn " . substr($path, strlen(static::STREAM_PROTO . '://')) . ";";
        $this->length  = strlen($this->content);

        return true;
    }

    public function stream_read($count): string
    {
        $value         = substr($this->content, $this->pointer, $count);
        $this->pointer += $count;

        return $value;
    }

    public function stream_eof(): bool
    {
        return $this->pointer >= $this->length;
    }

    public function stream_set_option($option, $arg1, $arg2): bool
    {
        return false;
    }

    public function stream_stat(): array
    {
        $stat    = stat(__FILE__);
        $stat[7] = $stat['size'] = $this->length;

        return $stat;
    }

    public function url_stat($path, $flags): array
    {
        $stat    = stat(__FILE__);
        $stat[7] = $stat['size'] = $this->length;

        return $stat;
    }

    public function stream_seek($offset, $whence = SEEK_SET): bool
    {
        $crt = $this->pointer;

        switch ($whence) {
            case SEEK_SET:
                $this->pointer = $offset;
                break;
            case SEEK_CUR:
                $this->pointer += $offset;
                break;
            case SEEK_END:
                $this->pointer = $this->length + $offset;
                break;
        }

        if ($this->pointer < 0 || $this->pointer >= $this->length) {
            $this->pointer = $crt;

            return false;
        }

        return true;
    }

    public function stream_tell(): int
    {
        return $this->pointer;
    }

    public static function register(): void
    {
        if (!static::$isRegistered) {
            static::$isRegistered = stream_wrapper_register(static::STREAM_PROTO, __CLASS__);
        }
    }
}
