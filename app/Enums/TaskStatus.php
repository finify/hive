<?php

namespace App\Enums;

enum TaskStatus: string implements HasTone
{
    case ToDo = 'to-do';
    case InProgress = 'in-progress';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::ToDo => 'To do',
            self::InProgress => 'In progress',
            self::Done => 'Done',
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::ToDo => Tone::Neutral,
            self::InProgress => Tone::Warning,
            self::Done => Tone::Positive,
        };
    }

    public function next(): self
    {
        return match ($this) {
            self::ToDo => self::InProgress,
            self::InProgress => self::Done,
            self::Done => self::ToDo,
        };
    }
}
