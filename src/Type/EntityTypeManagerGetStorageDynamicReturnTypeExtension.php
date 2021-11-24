<?php declare(strict_types=1);

namespace mglaman\PHPStanDrupal\Type;

use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;
use Drupal\Core\Entity\ContentEntityStorageInterface;
use mglaman\PHPStanDrupal\Drupal\EntityDataRepository;
use mglaman\PHPStanDrupal\Type\EntityStorage\ConfigEntityStorageType;
use mglaman\PHPStanDrupal\Type\EntityStorage\ContentEntityStorageType;
use mglaman\PHPStanDrupal\Type\EntityStorage\EntityStorageType;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\VariadicPlaceholder;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\ShouldNotHappenException;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\ObjectType;

class EntityTypeManagerGetStorageDynamicReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    /**
     * @var ReflectionProvider
     */
    private $reflectionProvider;

    /**
     * @var EntityDataRepository
     */
    private $entityDataRepository;

    /**
     * EntityTypeManagerGetStorageDynamicReturnTypeExtension constructor.
     *
     * @param ReflectionProvider $reflectionProvider
     * @param EntityDataRepository $entityDataRepository
     */
    public function __construct(ReflectionProvider $reflectionProvider, EntityDataRepository $entityDataRepository)
    {
        $this->reflectionProvider = $reflectionProvider;
        $this->entityDataRepository = $entityDataRepository;
    }

    public function getClass(): string
    {
        return 'Drupal\Core\Entity\EntityTypeManagerInterface';
    }

    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'getStorage';
    }

    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        MethodCall $methodCall,
        Scope $scope
    ): \PHPStan\Type\Type {
        $returnType = ParametersAcceptorSelector::selectSingle($methodReflection->getVariants())->getReturnType();
        if (!isset($methodCall->args[0])) {
            // Parameter is required.
            throw new ShouldNotHappenException();
        }

        $arg1 = $methodCall->args[0];
        if ($arg1 instanceof VariadicPlaceholder) {
            throw new ShouldNotHappenException();
        }
        $arg1 = $arg1->value;

        // @todo handle where the first param is EntityTypeInterface::id()
        if ($arg1 instanceof MethodCall) {
            // There may not be much that can be done, since it's a generic EntityTypeInterface.
            return $returnType;
        }
        // @todo handle concat ie: entity_{$display_context}_display for entity_form_display or entity_view_display
        if ($arg1 instanceof Concat) {
            return $returnType;
        }
        if (!$arg1 instanceof String_) {
            // @todo determine what these types are, and try to resolve entity name from.
            return $returnType;
        }

        $entityTypeId = $arg1->value;

        $storageClassName = $this->entityDataRepository->getStorageClassName($entityTypeId);
        if ($storageClassName !== null) {
            $interfaces = \array_keys($this->reflectionProvider->getClass($storageClassName)->getInterfaces());

            if (\in_array(ConfigEntityStorageInterface::class, $interfaces, true)) {
                return new ConfigEntityStorageType($entityTypeId, $storageClassName);
            }

            if (\in_array(ContentEntityStorageInterface::class, $interfaces, true)) {
                return new ContentEntityStorageType($entityTypeId, $storageClassName);
            }

            return new EntityStorageType($entityTypeId, $storageClassName);
        }

        // @todo get entity type class reflection and return proper storage for entity type
        // example: config storage, sqlcontententitystorage, etc.
        if ($returnType instanceof ObjectType) {
            return new EntityStorageType($entityTypeId, $returnType->getClassName());
        }
        return $returnType;
    }
}
