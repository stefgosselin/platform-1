<?php

namespace Oro\Bundle\UIBundle\Model;

class TreeItem implements \JsonSerializable
{
    /**
     * @param string $key
     * @param string $label
     */
    public function __construct($key, $label = '')
    {
        $this->key = $key;
        $this->label = $label;
    }

    /** @var int|string */
    private $key;

    /** @var string */
    private $label;

    /** @var TreeItem */
    private $parent;

    /** @var TreeItem[] */
    private $children = [];

    /**
     * @return int|string
     */
    public function getKey()
    {
        return $this->key;
    }

    /**
     * @return string
     */
    public function getLabel()
    {
        return $this->label;
    }

    /**
     * @return TreeItem
     */
    public function getParent()
    {
        return $this->parent;
    }

    /**
     * @param TreeItem $parent
     *
     * @return TreeItem
     */
    public function setParent(TreeItem $parent)
    {
        if ($parent === $this) {
            throw new \InvalidArgumentException('Item cannot be a child of itself');
        }

        $this->parent = $parent;
        $this->parent->addChild($this);

        return $this;
    }

    /**
     * Finds all parents recursively
     *
     * @param bool $includeRoot
     * @return array
     */
    public function getParents($includeRoot = false)
    {
        $parents = [];
        $currentParent = $this->parent;

        while ($currentParent !== null) {
            array_unshift($parents, $currentParent);
            $currentParent = $currentParent->getParent();
        }

        if (!$includeRoot && count($parents) > 0) {
            array_shift($parents);
        }

        return $parents;
    }

    /**
     * @return TreeItem[]
     */
    public function getChildren()
    {
        return $this->children;
    }

    /**
     * @param TreeItem $child
     *
     * @return TreeItem
     */
    public function addChild(TreeItem $child)
    {
        $this->children[$child->key] = $child;

        return $this;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->label;
    }

    public function jsonSerialize()
    {
        return [
            'id' => $this->key,
            'label' => $this->label,
            'children' => $this->children,
        ];
    }
}
