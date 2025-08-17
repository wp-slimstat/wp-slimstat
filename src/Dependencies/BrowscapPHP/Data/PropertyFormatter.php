<?php

declare(strict_types=1);

namespace SlimStat\Dependencies\BrowscapPHP\Data;

final class PropertyFormatter
{
    private PropertyHolder $propertyHolder;

    /**
     * @throws void
     */
    public function __construct(PropertyHolder $propertyHolder)
    {
        $this->propertyHolder = $propertyHolder;
    }

    /**
     * formats the name of a property
     *
     * @param bool|string $value
     *
     * @return bool|string
     *
     * @throws void
     */
    public function formatPropertyValue($value, string $property)
    {
        if (PropertyHolder::TYPE_BOOLEAN === $this->propertyHolder->getPropertyType($property)) {
            return true === $value || 'true' === $value || '1' === $value;
        }

        return $value;
    }
}
