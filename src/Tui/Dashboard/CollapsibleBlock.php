<?php

declare(strict_types=1);

namespace App\Tui\Dashboard;

use App\Tui\Style\Palette;
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
    private const array TOP_ONLY_BORDER = [1, 0, 0, 0];
    private const array FULL_BORDER = [1, 1, 1, 1];

    private readonly TextWidget $headerWidget;
    private readonly TextWidget $bodyTextWidget;
    private readonly ContainerWidget $bodyContainer;
    private readonly ContainerWidget $outerContainer;
    private readonly Palette $palette;

    private bool $expanded = false;
    private bool $hasData = false;
    private bool $selected = false;
    private string $summary = '';

    public function __construct(
        private readonly string $title,
        ?Palette $palette = null,
    ) {
        $this->palette = $palette ?? new Palette();
        $this->headerWidget = new TextWidget($this->renderHeaderLine());
        $this->bodyTextWidget = new TextWidget(self::EMPTY_BODY_TEXT);

        $this->bodyContainer = new ContainerWidget();
        $this->bodyContainer->add($this->bodyTextWidget);
        $this->bodyContainer->setStyle(new Style()->withHidden(true));

        $this->outerContainer = new ContainerWidget();
        $this->outerContainer->add($this->headerWidget);
        $this->outerContainer->add($this->bodyContainer);

        $this->applyOnOffStyle();
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
        $hasDataChanged = $this->hasData !== $hasData;
        $this->hasData = $hasData;
        $this->bodyTextWidget->setText($bodyText);
        $this->headerWidget->setText($this->renderHeaderLine());

        if ($hasDataChanged) {
            $this->applyOnOffStyle();
        }
    }

    public function setSelected(bool $selected): void
    {
        $this->selected = $selected;
        $this->headerWidget->setText($this->renderHeaderLine());
        $this->applyOnOffStyle();
    }

    public function setSummary(string $summary): void
    {
        $this->summary = $summary;
        $this->headerWidget->setText($this->renderHeaderLine());
    }

    public function summary(): string
    {
        return $this->summary;
    }

    public function headerText(): string
    {
        return $this->headerWidget->getText();
    }

    public function headerStyle(): ?Style
    {
        return $this->headerWidget->getStyle();
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
        $headerLine = $selectionPrefix.$this->title.' '.$stateMarker;

        if (!$this->expanded && '' !== $this->summary) {
            $headerLine .= ' '.$this->summary;
        }

        return $headerLine;
    }

    private function applyExpansionStyle(): void
    {
        $currentStyle = $this->bodyContainer->getStyle() ?? new Style();
        $this->bodyContainer->setStyle($currentStyle->withHidden(!$this->expanded));
        $this->headerWidget->setText($this->renderHeaderLine());
    }

    private function applyOnOffStyle(): void
    {
        if ($this->hasData) {
            $this->applyOnStyle();

            return;
        }

        $this->applyOffStyle();
    }

    private function applyOnStyle(): void
    {
        $this->headerWidget->setStyle(
            new Style()
                ->withBold(true)
                ->withColor($this->palette->headerText),
        );
        $this->outerContainer->setStyle(
            new Style()->withBorder(
                $this->borderSides(),
                color: $this->palette->borderAccent,
            ),
        );
    }

    private function applyOffStyle(): void
    {
        $this->headerWidget->setStyle(
            new Style()
                ->withDim(true)
                ->withColor($this->palette->dimText),
        );
        $this->outerContainer->setStyle(
            new Style()->withBorder(
                $this->borderSides(),
                color: $this->palette->borderDim,
            ),
        );
    }

    /**
     * @return array{int, int, int, int}
     */
    private function borderSides(): array
    {
        return $this->selected ? self::FULL_BORDER : self::TOP_ONLY_BORDER;
    }
}
