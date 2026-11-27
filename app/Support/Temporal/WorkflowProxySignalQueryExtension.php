<?php

declare(strict_types=1);

namespace App\Support\Temporal;

use LogicException;
use PHPStan\Analyser\OutOfClassScope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;
use Temporal\Internal\Client\WorkflowProxy;
use Temporal\Workflow\WorkflowMethod;

class WorkflowProxySignalQueryExtension implements MethodsClassReflectionExtension
{
    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        if ($classReflection->getName() !== WorkflowProxy::class) {
            return false;
        }

        $workflowReflection = $this->workflowReflection($classReflection);

        if ($workflowReflection === null || !$workflowReflection->hasMethod($methodName)) {
            return false;
        }

        $method = $workflowReflection->getMethod($methodName, new OutOfClassScope);

        if (!$method->isPublic()) {
            return false;
        }

        return $workflowReflection->getNativeReflection()
            ->getMethod($methodName)
            ->getAttributes(WorkflowMethod::class) === [];
    }

    public function getMethod(ClassReflection $classReflection, string $methodName): MethodReflection
    {
        $workflowReflection = $this->workflowReflection($classReflection);

        if ($workflowReflection === null) {
            throw new LogicException("Workflow type behind {$classReflection->getName()}::{$methodName}() is not resolvable.");
        }

        return $workflowReflection->getMethod($methodName, new OutOfClassScope);
    }

    private function workflowReflection(ClassReflection $classReflection): ?ClassReflection
    {
        $templateTypes = $classReflection->getActiveTemplateTypeMap();

        if ($templateTypes->count() !== 1) {
            return null;
        }

        $workflowType = $templateTypes->getType('T');

        if ($workflowType === null) {
            return null;
        }

        $reflections = $workflowType->getObjectClassReflections();

        return count($reflections) === 1 ? $reflections[0] : null;
    }
}
