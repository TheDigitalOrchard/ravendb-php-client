<?php

namespace RavenDB\Exceptions;

use RavenDB\Constants\HttpStatusCode;
use RavenDB\Exceptions\Documents\Compilation\IndexCompilationException;
use RavenDB\Exceptions\Documents\DocumentConflictException;
use RavenDB\Extensions\JsonExtensions;
use RavenDB\Http\HttpResponse;
use Throwable;

// !status: DONE
class ExceptionDispatcher
{
    public static function get(ExceptionSchema $schema, int $code, ?Throwable $inner = null): RavenException
    {
        $message = $schema->getMessage();
        $typeAsString = $schema->getType();

        if ($code == HttpStatusCode::CONFLICT) {
            if (strpos($typeAsString, 'DocumentConflictException') !== false) {
                return DocumentConflictException::fromMessage($message);
            }

            return new ConcurrencyException($message);
        }

        $error = $schema->getError() . PHP_EOL . "The server at " . $schema->getUrl() . " responded with status code: " . $code;

        $type = self::getType($typeAsString);
        if ($type == null) {
            return new RavenException($error, $inner);
        }

        try {
            $exception = new $type($error);
        } catch (Throwable $e) {
            return new RavenException($error, $inner);
        }

        if (!is_a($type, RavenException::class, true)) {
            return new RavenException($error, $exception);
        }

        return $exception;
    }

    public static function throwException(?HttpResponse $response = null): void
    {
        if ($response == null) {
            throw new IllegalArgumentException('Response cannot be null.');
        }

        try {
            $json = $response->getContent();
            /** @var ExceptionSchema $schema */
            $schema = JsonExtensions::getDefaultMapper()->deserialize($json, ExceptionSchema::class, 'json');

            if ($response->getStatusCode() == HttpStatusCode::CONFLICT) {
                self::throwConflict($schema, $json);
            }

            $type = self::getType($schema->getType());
            if ($type == null) {
                throw RavenException::generic($schema->getError(), $json);
            }

            $exception = new RavenException();

            try {
                $exception = new $type($schema->getError());
            } catch (Throwable $e) {
                throw RavenException::generic($schema->getError(), $json);
            }
            if (!($exception instanceof RavenException)) {
                throw new RavenException($schema->getError(), $exception);
            }

            if ($exception instanceof IndexCompilationException) {
                /** @var IndexCompilationException $indexCompilationException */
                $indexCompilationException = $exception;
                $jsonNode = JsonExtensions::getDefaultMapper()->decode($json, 'json');
                $indexDefinitionProperty = array_key_exists('TransformerDefinitionProperty', $jsonNode) ?  $jsonNode['TransformerDefinitionProperty'] : null;
                if ($indexDefinitionProperty != null) {
                    $indexCompilationException->setIndexDefinitionProperty($indexDefinitionProperty);
                }

                $problematicText = array_key_exists('ProblematicText', $jsonNode) ?  $jsonNode['ProblematicText'] : null;
                if ($problematicText != null) {
                    $indexCompilationException->setProblematicText($problematicText);
                }

                throw $indexCompilationException;
            }

            throw $exception;

        } catch (Throwable $exception) {
            if ($exception instanceof RavenException) {
                throw $exception;
            }

            throw new RavenException($exception->getMessage(), $exception);
        }
    }


    /**
     * @throws BadResponseException
     * @throws ConcurrencyException
     * @throws DocumentConflictException
     */
    private static function throwConflict(ExceptionSchema $schema, string $json): void
    {
        if (strpos($schema->getType(), 'DocumentConflictException') !== false) {
            throw DocumentConflictException::fromJson($json);
        }

        throw new ConcurrencyException($schema->getError());
    }

    private static function getType(?string $typeAsString): ?string
    {
        if ($typeAsString == "System.TimeoutException") {
            return TimeoutException::class;
        }

        if ($typeAsString == "System.ArgumentNullException") {
            return IllegalArgumentException::class;
        }

        $prefix = "Raven.Client.Exceptions.";

        if (str_starts_with($typeAsString, $prefix)) {
            $exceptionName = substr($typeAsString, strlen($prefix));

            if (strpos($exceptionName, '.') != false) {
                $tokens = preg_split("/\\./", $exceptionName);

//                for( $i = 0; $i<count($tokens); $i++) {
//                    $tokens[$i] = strtolower($tokens[$i]);
//                }
                $exceptionName = join("\\", $tokens);
            }

            try {
                return 'RavenDB\\Exceptions\\' . $exceptionName;
            } catch (Throwable $exception) {
                return null;
            }
        }

        return null;
    }
}
