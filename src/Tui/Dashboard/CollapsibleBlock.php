<?php

declare(strict_types=1);

namespace App\Tui\Dashboard;

use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\TextWidget;

final class CollapsibleBlock
{
    private const string STATE_MARKER_ON = '[on]';
    private const string STATE_MARKER_OFF = '[off]';
    private const string SELECTION_PREFIX_SELECTED = '> ';
    private const string SELECTION_PREFIX_UNSELECTED = '  ';
    private const string EMPTY_BODY_TEXT = 'no data';

    private readonly TextWidget $headerWidget;
    private readonly TextWidget $bodyTextWidget;
    private readonly ContainerWidget $bodyContainer;
    private readonly ContainerWidget $outerContainer;

    private bool $expanded = false;
    private bool $hasData = false;
    private bool $selected = false;

    public function __construct(private readonly string $title)
    {
        $this->headerWidget = new TextWidget($this->renderHeaderLine());
        $this->bodyTextWidget = new TextWidget(self::EMPTY_BODY_TEXT);

        $this->bodyContainer = new ContainerWidget();
        $this->bodyContainer->add($this->bodyTextWidget);
        $this->bodyContainer->setStyle(new Style()->withHidden(true));

        $this->outerContainer = new ContainerWidget();
        $this->outerContainer->add($this->headerWidget);
        $this->outerContainer->add($this->bodyContainer);
    }

    public function widget(): ContainerWidget
    {
        return $this->outerContainer;
    }

    public function toggle(): void
    {
        if ($this->expanded) {
            $this->collapse();

            return;
        }
        $this->expand();
    }

    public function expand(): void
    {
        $this->expanded = true;
        $this->applyExpansionStyle();
    }

    public function collapse(): void
    {
        $this->expanded = false;
        $this->applyExpansionStyle();
    }

    public function isExpanded(): bool
    {
        return $this->expanded;
    }

    public function setState(bool $hasData, string $bodyText): void
    {
        $this->hasData = $hasData;
        $this->bodyTextWidget->setText($bodyText);
        $this->headerWidget->setText($this->renderHeaderLine());
    }

    public function setSelected(bool $selected): void
    {
        $this->selected = $selected;
        $this->headerWidget->setText($this->renderHeaderLine());
    }

    public function headerText(): string
    {
        return $this->headerWidget->getText();
    }

    public function bodyText(): string
    {
        return $this->bodyTextWidget->getText();
    }

    public function bodyContainer(): ContainerWidget
    {
        return $this->bodyContainer;
    }

    private function renderHeaderLine(): string
    {
        $selectionPrefix = $this->selected ? self::SELECTION_PREFIX_SELECTED : self::SELECTION_PREFIX_UNSELECTED;
        $stateMarker = $this->hasData ? self::STATE_MARKER_ON : self::STATE_MARKER_OFF;

        return $selectionPrefix.$this->title.' '.$stateMarker;
    }

    private function applyExpansionStyle(): void
    {
        $currentStyle = $this->bodyContainer->getStyle() ?? new Style();
        $this->bodyContainer->setStyle($currentStyle->withHidden(!$this->expanded));
    }
}
