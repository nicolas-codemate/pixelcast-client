<?php

declare(strict_types=1);

namespace App\Tui\Overlay;

use App\Tui\Style\Palette;
use Symfony\Component\Tui\Style\Border;
use Symfony\Component\Tui\Style\BorderPattern;
use Symfony\Component\Tui\Style\Padding;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

final class OverlayMenu
{
    private const string FOOTER_HINT = '[Esc] close';

    private readonly SelectListWidget $selectList;
    private readonly TextWidget $titleWidget;
    private readonly TextWidget $footerWidget;
    private readonly ContainerWidget $container;

    /**
     * @param list<array{value: string, label: string}> $items
     */
    public function __construct(array $items, string $title = 'Menu', ?Palette $palette = null)
    {
        $palette ??= new Palette();

        $this->titleWidget = new TextWidget($title);
        $this->titleWidget->setStyle(
            new Style()
                ->withBold(true)
                ->withColor($palette->accentText),
        );

        $this->selectList = new SelectListWidget(items: $items, maxVisible: max(1, \count($items)));

        $this->footerWidget = new TextWidget(self::FOOTER_HINT);
        $this->footerWidget->setStyle(
            new Style()
                ->withDim(true)
                ->withColor($palette->dimText),
        );

        $this->container = new ContainerWidget();
        $this->container->add($this->titleWidget);
        $this->container->add($this->selectList);
        $this->container->add($this->footerWidget);
        $this->container->setStyle(new Style(
            padding: Padding::from([0, 1]),
            border: Border::from([1], pattern: BorderPattern::rounded(), color: $palette->borderAccent),
            background: 'black',
            hidden: true,
        ));
    }

    public function widget(): ContainerWidget
    {
        return $this->container;
    }

    public function selectListWidget(): SelectListWidget
    {
        return $this->selectList;
    }

    public function show(): void
    {
        $this->setHidden(false);
    }

    public function hide(): void
    {
        $this->setHidden(true);
    }

    private function setHidden(bool $hidden): void
    {
        $currentStyle = $this->container->getStyle() ?? new Style();
        $this->container->setStyle($currentStyle->withHidden($hidden));
    }
}
