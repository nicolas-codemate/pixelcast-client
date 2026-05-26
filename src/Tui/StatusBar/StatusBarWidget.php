<?php

declare(strict_types=1);

namespace App\Tui\StatusBar;

use App\Tui\TuiMode;
use Symfony\Component\Tui\Style\Direction;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\TextWidget;

final class StatusBarWidget
{
    private const string UNSAVED_INDICATOR = 'UNSAVED CHANGES';
    private const string SEPARATOR = '   ';

    private readonly ContainerWidget $container;
    private readonly TextWidget $chipWidget;
    private readonly Style $chipStyle;
    private readonly TextWidget $restLineWidget;
    private string $baseLine = '';
    private bool $hasUnsavedChanges = false;

    public function __construct(TuiMode $mode)
    {
        $this->chipStyle = $this->buildChipStyle($mode);
        $this->chipWidget = new TextWidget(' '.$mode->displayLabel().' ');
        $this->chipWidget->setStyle($this->chipStyle);

        $this->restLineWidget = new TextWidget('');

        $this->container = new ContainerWidget();
        $this->container->setStyle(new Style(direction: Direction::Horizontal, gap: 1));
        $this->container->add($this->chipWidget);
        $this->container->add($this->restLineWidget);
    }

    public function setBaseLine(string $line): void
    {
        if ($this->baseLine === $line) {
            return;
        }

        $this->baseLine = $line;
        $this->refreshRestLine();
    }

    public function setUnsavedChanges(bool $hasUnsaved): void
    {
        if ($this->hasUnsavedChanges === $hasUnsaved) {
            return;
        }

        $this->hasUnsavedChanges = $hasUnsaved;
        $this->refreshRestLine();
    }

    public function widget(): ContainerWidget
    {
        return $this->container;
    }

    public function chipText(): string
    {
        return $this->chipWidget->getText();
    }

    public function chipStyle(): Style
    {
        return $this->chipStyle;
    }

    public function restLineText(): string
    {
        return $this->restLineWidget->getText();
    }

    private function refreshRestLine(): void
    {
        $text = $this->baseLine;
        if ($this->hasUnsavedChanges) {
            $text .= self::SEPARATOR.self::UNSAVED_INDICATOR;
        }

        $this->restLineWidget->setText($text);
    }

    private function buildChipStyle(TuiMode $mode): Style
    {
        return match ($mode) {
            TuiMode::Dev => new Style(background: 'green', color: 'black', bold: true),
            TuiMode::Prod => new Style(background: 'red', color: 'white', bold: true),
        };
    }
}
