<?php

namespace Crescat\SaloonSdkGenerator\Generators;

use cebe\openapi\spec\MediaType;
use Crescat\SaloonSdkGenerator\Data\Generator\ApiSpecification;
use Crescat\SaloonSdkGenerator\Data\Generator\Endpoint;
use Crescat\SaloonSdkGenerator\Data\Generator\Parameter;
use Crescat\SaloonSdkGenerator\Generator;
use Crescat\SaloonSdkGenerator\Helpers\MethodGeneratorHelper;
use Crescat\SaloonSdkGenerator\Helpers\NameHelper;
use Crescat\SaloonSdkGenerator\Helpers\Utils;
use DateTime;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\Literal;
use Nette\PhpGenerator\PhpFile;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method as SaloonHttpMethod;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

class RequestGenerator extends Generator
{
    public function generate(ApiSpecification $specification): PhpFile|array
    {
        $classes = [];

        foreach ($specification->endpoints as $endpoint) {
            $classes[] = $this->generateRequestClass($endpoint);
        }

        return $classes;
    }

    protected function generateRequestClass(Endpoint $endpoint): PhpFile
    {
        $resourceName = NameHelper::resourceClassName($endpoint->collection ?: $this->config->fallbackResourceName);
        $className = NameHelper::requestClassName($endpoint->name);

        $classType = new ClassType($className);

        $classFile = new PhpFile;
        $namespace = $classFile
            ->addNamespace("{$this->config->namespace}\\{$this->config->requestNamespaceSuffix}\\{$resourceName}");

        $classType->setExtends(Request::class)
            ->setComment($endpoint->name)
            ->addComment('')
            ->addComment(Utils::wrapLongLines($endpoint->description ?? ''));

        // TODO: We assume JSON body if post/patch, make these assumptions configurable in the future.
        if ($endpoint->method->isPost() || $endpoint->method->isPatch()) {
            $classType
                ->addImplement(HasBody::class)
                ->addTrait(HasJsonBody::class);

            $namespace
                ->addUse(HasBody::class)
                ->addUse(HasJsonBody::class);
        }

        $classType->addProperty('method')
            ->setProtected()
            ->setType(SaloonHttpMethod::class)
            ->setValue(
                new Literal(
                    sprintf('Method::%s', $endpoint->method->value)
                )
            );

        $classType->addMethod('resolveEndpoint')
            ->setPublic()
            ->setReturnType('string')
            ->addBody(
                collect($endpoint->pathSegments)
                    ->map(function ($segment) {
                        return Str::startsWith($segment, ':')
                            ? new Literal(sprintf('{$this->%s}', NameHelper::safeVariableName($segment)))
                            : $segment;
                    })
                    ->pipe(function (Collection $segments) {
                        return new Literal(sprintf('return "/%s";', $segments->implode('/')));
                    })

            );

        /** @var MediaType $mediaType */
        $mediaType = Arr::first(Arr::get($endpoint->response, 200)?->content);

        if ($mediaType && ($type = DtoGenerator::convertOpenApiTypeToPhp($mediaType?->schema)) !== 'array') {
            $method = $classType->addMethod('createDtoFromResponse')
                ->setPublic()
                ->setReturnType('mixed')
                ->addBody(
                    new Literal(sprintf('return %s::from($response->json());', $type))
                );
            $method->addParameter('response')
                ->setType(Response::class);
            $namespace->addUse(Response::class);

            $method->addComment("@throws \\JsonException\n@return $type");

            $namespace->addUse("{$this->config->namespace}\\{$this->config->dtoNamespaceSuffix}\\{$type}");
        }

        $classConstructor = $classType->addMethod('__construct');

        // Priority 1. - Path Parameters
        foreach ($endpoint->pathParameters as $pathParam) {
            MethodGeneratorHelper::addParameterAsPromotedProperty($classConstructor, $pathParam);
        }

        // Priority 2. - Body Parameters
        if (! empty($endpoint->bodyParameters)) {
            $bodyParams = collect($endpoint->bodyParameters)
                ->reject(fn (Parameter $parameter) => in_array($parameter->name, $this->config->ignoredBodyParams))
                ->values()
                ->toArray();

            foreach ($bodyParams as $bodyParam) {
                MethodGeneratorHelper::addParameterAsPromotedProperty($classConstructor, $bodyParam);
            }

            MethodGeneratorHelper::generateArrayReturnMethod($classType, 'defaultBody', $bodyParams, withArrayFilterWrapper: true);
        }

        // Priority 3. - Query Parameters
        if (! empty($endpoint->queryParameters)) {
            $queryParams = collect($endpoint->queryParameters)
                ->reject(fn (Parameter $parameter) => in_array($parameter->name, $this->config->ignoredQueryParams))
                ->values()
                ->toArray();

            foreach ($queryParams as $queryParam) {
                MethodGeneratorHelper::addParameterAsPromotedProperty($classConstructor, $queryParam);
            }

            MethodGeneratorHelper::generateArrayReturnMethod($classType, 'defaultQuery', $queryParams, withArrayFilterWrapper: true);
        }

        $namespace
            ->addUse(SaloonHttpMethod::class)
            ->addUse(DateTime::class)
            ->addUse(Request::class)
            ->add($classType);

        return $classFile;
    }
}
