<?php

namespace Karboosx\Procer\Debug;

use Karboosx\Procer\Parser\Node\AbstractNode;

abstract class ProcerReflection
{
    protected function traverse(AbstractNode $node, callable $callback): void
    {
        $properties = get_object_vars($node);

        foreach ($properties as $property) {
            if ($property instanceof AbstractNode) {
                $callback($property);
                $this->traverse($property, $callback);
            }

            if (is_array($property)) {
                foreach ($property as $item) {
                    if ($item instanceof AbstractNode) {
                        $callback($item);
                        $this->traverse($item, $callback);
                    }
                }
            }
        }
    }
}