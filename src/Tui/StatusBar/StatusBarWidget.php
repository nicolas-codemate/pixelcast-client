<?php

declare(strict_types=1);

namespace App\Tui\StatusBar;

use Symfony\Component\Tui\Widget\TextWidget;

final class StatusBarWidget
{
    private const string UNSAVED_INDICATOR = 'UNSAVED CHANGES';
    private const string SEPARATOR = '   ';

    private readonly TextWidget $textWidget;
    private string $baseLine = '';
    private bool $hasUnsavedChanges = false;

    public function __construct()
    {
        $this->textWidget = new TextWidget('');
    }

    public function setBaseLine(string $line): void
    {
        if ($this->baseLine === $line) {
            return;
        }

        $this->baseLine = $line;
        $this->refreshText();
    }

    public function setUnsavedChanges(bool $hasUnsaved): void
    {
        if ($this->hasUnsavedChanges === $hasUnsaved) {
            return;
        }

        $this->hasUnsavedChanges = $hasUnsaved;
        $this->refreshText();
    }

    public function widget(): TextWidget
    {
        return $this->textWidget;
    }

    private function refreshText(): void
    {
        $text = $this->baseLine;
        if ($this->hasUnsavedChanges) {
            $text .= self::SEPARATOR.self::UNSAVED_INDICATOR;
        }

        $this->textWidget->setText($text);
    }
}
