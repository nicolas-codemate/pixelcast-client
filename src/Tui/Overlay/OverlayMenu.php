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
     * @var list<array{value: string, label: string}>
     */
    private array $items;

    /**
     * @param list<array{value: string, label: string}> $items
     */
    public function __construct(array $items)
    {
        $this->items = $items;
        $this->selectList = new SelectListWidget(items: $items, maxVisible: max(1, \count($items)));

        $this->container = new ContainerWidget();
        $this->container->add($this->selectList);
        $this->container->setStyle($this->buildHiddenStyle());
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

    public function isVisible(): bool
    {
        return true !== $this->container->getStyle()?->getHidden();
    }

    /**
     * @param list<array{value: string, label: string}> $items
     */
    public function setItems(array $items): void
    {
        $this->items = $items;
        $this->selectList->setItems($items);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function items(): array
    {
        return $this->items;
    }

    private function buildHiddenStyle(): Style
    {
        return new Style(
            padding: Padding::from([0, 1]),
            border: Border::from([1]),
            background: 'black',
            hidden: true,
        );
    }

    private function setHidden(bool $hidden): void
    {
        $currentStyle = $this->container->getStyle() ?? new Style();
        $this->container->setStyle($currentStyle->withHidden($hidden));
    }
}
