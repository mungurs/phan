<?php declare(strict_types=1);
namespace Phan\Language\Element;

use Phan\CodeBase;
use Phan\Exception\CodeBaseException;
use Phan\Language\Context;
use Phan\Language\FQSEN;
use Phan\Language\FQSEN\FullyQualifiedClassElement;
use Phan\Language\FQSEN\FullyQualifiedClassName;
use Phan\Language\UnionType;

abstract class ClassElement extends AddressableElement
{
    /** @var FullyQualifiedClassName */
    private $class_fqsen;

    public function __construct(
        Context $context,
        string $name,
        UnionType $type,
        int $flags,
        FullyQualifiedClassElement $fqsen
    ) {
        parent::__construct($context, $name, $type, $flags, $fqsen);
        $this->class_fqsen = $fqsen->getFullyQualifiedClassName();
    }

    /**
     * @param FullyQualifiedClassElement $fqsen
     * @return void
     * @override
     * @suppress PhanParamSignatureMismatch deliberately more specific
     */
    public function setFQSEN(FQSEN $fqsen)
    {
        \assert($fqsen instanceof FullyQualifiedClassElement);
        parent::setFQSEN($fqsen);
        $this->class_fqsen = $fqsen->getFullyQualifiedClassName();
    }

    /**
     * @var FullyQualifiedClassElement|null
     * The FQSEN of this element where it is originally
     * defined.
     */
    private $defining_fqsen = null;

    /**
     * @return bool
     * True if this element has a defining FQSEN defined
     */
    public function hasDefiningFQSEN() : bool
    {
        return ($this->defining_fqsen != null);
    }

    /**
     * @return FullyQualifiedClassElement
     * The FQSEN of this class element from where it was
     * originally defined
     */
    public function getDefiningFQSEN() : FullyQualifiedClassElement
    {
        return $this->defining_fqsen;
    }

    /**
     * @return FullyQualifiedClassName
     * The FQSEN of this class element from where it was
     * originally defined
     */
    public function getDefiningClassFQSEN() : FullyQualifiedClassName
    {
        if (\is_null($this->defining_fqsen)) {
            throw new CodeBaseException(
                $this->getFQSEN(),
                "No defining class for {$this->getFQSEN()}"
            );
        }
        return $this->defining_fqsen->getFullyQualifiedClassName();
    }

    /**
     * @param FullyQualifiedClassElement $defining_fqsen
     * The FQSEN of this class element in the location in which
     * it was originally defined
     */
    public function setDefiningFQSEN(
        FullyQualifiedClassElement $defining_fqsen
    ) {
        $this->defining_fqsen = $defining_fqsen;
    }

    /**
     * @return Clazz
     * The class on which this element was originally defined
     */
    public function getDefiningClass(CodeBase $code_base) : Clazz
    {
        $class_fqsen = $this->getDefiningClassFQSEN();

        if (!$code_base->hasClassWithFQSEN($class_fqsen)) {
            throw new CodeBaseException(
                $class_fqsen,
                "Defining class $class_fqsen for {$this->getFQSEN()} not found"
            );
        }

        return $code_base->getClassByFQSEN($class_fqsen);
    }

    /**
     * @return FullyQualifiedClassName
     * The FQSEN of the class on which this element lives
     */
    public function getClassFQSEN() : FullyQualifiedClassName
    {
        return $this->class_fqsen;
    }

    /**
     * @param CodeBase $code_base
     * The code base with which to look for classes
     *
     * @return Clazz
     * The class that defined this element
     *
     * @throws CodeBaseException
     * An exception may be thrown if we can't find the
     * class
     */
    public function getClass(
        CodeBase $code_base
    ) : Clazz {
        $class_fqsen = $this->class_fqsen;

        if (!$code_base->hasClassWithFQSEN($class_fqsen)) {
            throw new CodeBaseException(
                $class_fqsen,
                "Defining class $class_fqsen for {$this->getFQSEN()} not found"
            );
        }

        return $code_base->getClassByFQSEN($class_fqsen);
    }

    /**
     * @return bool
     * True if this method overrides another method
     */
    public function getIsOverride() : bool
    {
        return $this->getPhanFlagsHasState(Flags::IS_OVERRIDE);
    }

    /**
     * @param bool $is_override
     * True if this method overrides another method
     *
     * @return void
     */
    public function setIsOverride(bool $is_override)
    {
        $this->setPhanFlags(Flags::bitVectorWithState(
            $this->getPhanFlags(),
            Flags::IS_OVERRIDE,
            $is_override
        ));
    }

    /**
     * @return bool
     * True if this is a static method
     */
    public function isStatic() : bool
    {
        return $this->getFlagsHasState(\ast\flags\MODIFIER_STATIC);
    }

    /**
     * @param CodeBase $code_base
     * The code base in which this element exists.
     *
     * @return bool
     * True if this is an internal element
     */
    public function isNSInternal(CodeBase $code_base) : bool
    {
        return (
            parent::isNSInternal($code_base)
            || $this->getClass($code_base)->isNSInternal($code_base)
        );
    }

    public function getElementNamespace() : string
    {
        // Get the namespace that the class is within
        return $this->getClassFQSEN()->getNamespace() ?: '\\';
    }

    /**
     * @param CodeBase $code_base used for access checks to protected properties
     * @param ?FullyQualifiedClassName $accessing_class_fqsen the class FQSEN of the current scope.
     *                                    null if in the global scope.
     */
    public function isAccessibleFromClass(CodeBase $code_base, $accessing_class_fqsen) : bool
    {
        if ($this->isPublic()) {
            return true;
        }
        if (!$accessing_class_fqsen) {
            // Accesses from outside class scopes can only access public fqsens
            return false;
        }
        $defining_fqsen = $this->getDefiningClassFQSEN();
        if ($defining_fqsen === $accessing_class_fqsen) {
            return true;
        }
        if ($this->isPrivate()) {
            return false;
        }
        return $this->checkCanAccessProtectedElement($code_base, $defining_fqsen, $accessing_class_fqsen);
    }

    /**
     * Check if a class can access a protected property defined in another class.
     *
     * Precondition: The property in $defining_fqsen is protected.
     */
    private function checkCanAccessProtectedElement(CodeBase $code_base, FullyQualifiedClassName $defining_fqsen, FullyQualifiedClassName $accessing_class_fqsen) : bool
    {
        $accessing_class_type = $accessing_class_fqsen->asType();
        $type_of_class_of_property = $defining_fqsen->asType();

        // If the definition of the property is protected,
        // then the subclasses of the defining class can access it.
        foreach ($accessing_class_type->asExpandedTypes($code_base)->getTypeSet() as $type) {
            if ($type->canCastToType($type_of_class_of_property)) {
                return true;
            }
        }
        // and base classes of the defining class can access it
        foreach ($type_of_class_of_property->asExpandedTypes($code_base)->getTypeSet() as $type) {
            if ($type->canCastToType($accessing_class_type)) {
                return true;
            }
        }
        return false;
    }
}
