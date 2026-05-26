<?php

declare(strict_types=1);

namespace App\Tui\Overlay;

use Symfony\Component\Tui\Style\Border;
use Symfony\Component\Tui\Style\Padding;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;

final class OverlayMenu
{
    private readonly SelectListWidget $selectList;
    private readonly ContainerWidget $container;

    /**
     * @param list<array{value: string, label: string}> $items
     */
    public function __construct(array $items)
    {
        $this->selectList = new SelectListWidget(items: $items, maxVisible: max(1, \count($items)));

        $this->container = new ContainerWidget();
        $this->container->add($this->selectList);
        $this->container->setStyle(new Style(
            padding: Padding::from([0, 1]),
            border: Border::from([1]),
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
