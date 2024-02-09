<?php

namespace Crescat\SaloonSdkGenerator\Generators;

use cebe\openapi\spec\Reference;
use cebe\openapi\spec\Schema;
use Crescat\SaloonSdkGenerator\Data\Generator\ApiSpecification;
use Crescat\SaloonSdkGenerator\Generator;
use Crescat\SaloonSdkGenerator\Helpers\NameHelper;
use Crescat\SaloonSdkGenerator\Helpers\Utils;
use Illuminate\Support\Str;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\EnumType;
use Nette\PhpGenerator\Literal;
use Nette\PhpGenerator\PhpFile;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

class DtoGenerator extends Generator
{
    protected array $generated = [];

    public function generate(ApiSpecification $specification): PhpFile|array
    {

        // TODO: since we are resolving the references, we get dupliate DTOs, this generator must be ran without reference resolution so we can handle references internally (generate only the base schema instead of duplicating the same dto with a different name)

        if ($specification->components) {
            foreach ($specification->components->schemas as $className => $schema) {
                $this->generateDtoClass(NameHelper::safeClassName($className), $schema);
            }
        }

        return $this->generated;
    }

    protected function generateDtoClass($className, Schema $schema)
    {

        /** @var Schema[] $properties */
        $properties = $schema->properties ?? [];

        $dtoName = NameHelper::dtoClassName($className ?: $this->config->fallbackResourceName);

        $classType = new ClassType($dtoName);
        $classFile = new PhpFile;
        $namespace = $classFile
            ->addNamespace("{$this->config->namespace}\\{$this->config->dtoNamespaceSuffix}");

        if ($schema->enum) {
            $classType = new EnumType($dtoName, $namespace);
            $classType->setType($schema->type);

            foreach ($schema->enum as $value) {
                $classType->addCase(Str::of($value)->snake()->upper(), $value);
            }

            $namespace->add($classType);

            $this->generated[$dtoName] = $classFile;

            return $classFile;
        }

        $classType->setExtends(Data::class)
            ->setComment($schema->title ?? '')
            ->addComment('')
            ->addComment(Utils::wrapLongLines($schema->description ?? ''));

        $classConstructor = $classType->addMethod('__construct');

        $generatedMappings = false;

        uksort($properties, function ($name1, $name2) use ($properties, $schema) {
            $property1Required = in_array($name1, $schema->required ?? []);
            $property2Required = in_array($name2, $schema->required ?? []);

            if ($property1Required && $property2Required) {
                return $name2 <=> $name1;
            }

            if (str_starts_with($name1, '@') && !str_starts_with($name2, '@')) {
                return -1;
            }

            if (str_starts_with($name2, '@') && !str_starts_with($name1, '@')) {
                return -1;
            }

            return $property1Required ? -1 : 1;
        });

        foreach ($properties as $propertyName => $propertySpec) {

            $type = $this->convertOpenApiTypeToPhp($propertySpec);
            $sub = NameHelper::dtoClassName($type);

            if ($type === 'object' || $type == 'array') {
                if (! isset($this->generated[$sub]) && ! empty($propertySpec->items)) {
                    $this->generated[$sub] = $this->generateDtoClass($propertyName, $propertySpec);
                }
            }

            $name = NameHelper::safeVariableName($propertyName);

            $property = $classConstructor->addPromotedParameter($name)
                ->setType($propertySpec instanceof Reference ? $namespace->resolveName($sub) : $type)
                ->setPublic();

            if ($type === 'array') {
                $type = DataCollection::class;
                $namespace->addUse($type);

                $property->setType($type);
                $property->addAttribute(DataCollectionOf::class, [new Literal($this->convertOpenApiTypeToPhp($propertySpec->items) .  '::class')]);

                $namespace->addUse(DataCollectionOf::class);
            }

            if (!in_array($propertyName, $schema->required ?? [])) {
                $property->setNullable()->setDefaultValue(null);
            }

            if ($name != $propertyName) {
                $property->addAttribute(MapName::class, [$propertyName]);
                $generatedMappings = true;
            }
        }

        $namespace->addUse(Data::class, alias: 'SpatieData')->add($classType);

        if ($generatedMappings) {
            $namespace->addUse(MapName::class);
        }

        $this->generated[$dtoName] = $classFile;

        return $classFile;
    }

    public static function convertOpenApiTypeToPhp(Schema|Reference $schema)
    {
        if ($schema instanceof Reference) {
            return Str::afterLast($schema->getReference(), '/');
        }

        if (is_array($schema->type)) {
            return collect($schema->type)->map(fn ($type) => $this->mapType($type))->implode('|');
        }

        if (is_string($schema->type)) {
            return static::mapType($schema->type, $schema->format);
        }

        return 'mixed';
    }

    protected static function mapType($type, $format = null): string
    {
        return match ($type) {
            'integer' => 'int',
            'string' => 'string',
            'boolean' => 'bool',
            'object' => 'object', // Recurse
            'number' => match ($format) {
                'float' => 'float',
                'int32', 'int64	' => 'int',
                default => 'int|float',
            },
            'array' => 'array',
            'null' => 'null',
            default => 'mixed',
        };
    }
}
