<?php

namespace RavenDB\Documents\Commands\Batches;

use RavenDB\Documents\Conventions\DocumentConventions;
use RavenDB\Documents\Session\TransactionMode;
use RavenDB\Exceptions\IllegalArgumentException;
use RavenDB\Exceptions\IllegalStateException;
use RavenDB\Http\HttpRequest;
use RavenDB\Http\HttpRequestInterface;
use RavenDB\Http\RavenCommand;
use RavenDB\Http\ResultInterface;
use RavenDB\Http\ServerNode;
use RavenDB\Json\BatchCommandResult;
use RavenDB\Primitives\CleanCloseable;
use RavenDB\Utils\TimeUtils;

// @todo: implement this
class SingleNodeBatchCommand extends RavenCommand implements CleanCloseable
{
    private bool $supportsAtomicWrites = false;
    private array $attachmentStreams = [];
    private ?DocumentConventions $conventions;
    private array $commands = [];
    private ?BatchOptions $options;
    private TransactionMode $mode;

    /**
     * @throws IllegalArgumentException
     */
    public function __construct(
        ?DocumentConventions $conventions,
        ?array $commands,
        ?BatchOptions $options = null,
        ?TransactionMode $mode = null
    ) {
        parent::__construct(BatchCommandResult::class);

        $this->conventions = $conventions;
        $this->commands = $commands ?? [];
        $this->options = $options;
        $this->mode = $mode ?? TransactionMode::singleNode();

        if ($conventions == null) {
            throw new IllegalArgumentException("conventions cannot be null");
        }

        if ($commands == null) {
            throw new IllegalArgumentException("commands cannot be null");
        }

        /** @var CommandDataInterface $command */
        foreach ($commands as $command) {
            if ($command instanceof PutAttachmentCommandData) {

                /** @var PutAttachmentCommandData $putAttachmentCommandData */
                $putAttachmentCommandData = $command;

                if ($this->$this->attachmentStreams == null) {
                    $this->attachmentStreams = [];
                }

                $stream = $putAttachmentCommandData->getStream();
                if (in_array($stream, $this->attachmentStreams)) {
                    PutAttachmentCommandHelper::throwStreamWasAlreadyUsed();
                } else {
                    $this->attachmentStreams[] = $stream;
                }
            }
        }
    }

    public function createRequest(ServerNode $serverNode): HttpRequestInterface
    {
        $request = new HttpRequest($this->createUrl($serverNode), HttpRequest::POST);

        $commands = [];
        /** @var CommandDataInterface $command */
        foreach ($this->commands as $command) {
            $commands[] = $command->serialize($this->conventions);
        }

        $request->setOptions([
            'headers' => [
                'Accept' => 'application/json',
            ],
            'json' => [
                'Commands' => $commands
            ]
        ]);


//        request.setEntity(new ContentProviderHttpEntity(outputStream -> {
//            try (JsonGenerator generator = mapper.getFactory().createGenerator(outputStream)) {
//                if (_supportsAtomicWrites == null || node.isSupportsAtomicClusterWrites() != _supportsAtomicWrites) {
//                    _supportsAtomicWrites = node.isSupportsAtomicClusterWrites();
//                }
//
//                generator.writeStartObject();
//                generator.writeFieldName("Commands");
//                generator.writeStartArray();
//
//                if (_supportsAtomicWrites) {
//                    for (ICommandData command : _commands) {
//                        command.serialize(generator, _conventions);
//                    }
//                } else {
//                    for (ICommandData command : _commands) {
//                        ByteArrayOutputStream baos = new ByteArrayOutputStream();
//                        try (JsonGenerator itemGenerator = mapper.createGenerator(baos)) {
//                            command.serialize(itemGenerator, _conventions);
//                        }
//
//                        ObjectNode itemNode = (ObjectNode) mapper.readTree(baos.toByteArray());
//                        itemNode.remove("OriginalChangeVector");
//                        generator.writeObject(itemNode);
//                    }
//                }
//
//                generator.writeEndArray();
//
//                if (_mode == TransactionMode.CLUSTER_WIDE) {
//                    generator.writeStringField("TransactionMode", "ClusterWide");
//                }
//
//                generator.writeEndObject();
//            } catch (IOException e) {
//                throw new RuntimeException(e);
//            }
//        }, ContentType.APPLICATION_JSON));
//
//
//        if (_attachmentStreams != null && _attachmentStreams.size() > 0) {
//            MultipartEntityBuilder entityBuilder = MultipartEntityBuilder.create();
//
//            HttpEntity entity = request.getEntity();
//
//            try {
//                ByteArrayOutputStream baos = new ByteArrayOutputStream();
//                entity.writeTo(baos);
//
//                entityBuilder.addBinaryBody("main", new ByteArrayInputStream(baos.toByteArray()));
//            } catch (IOException e) {
//                throw new RavenException("Unable to serialize BatchCommand", e);
//            }
//
//            int nameCounter = 1;
//
//            for (InputStream stream : _attachmentStreams) {
//                InputStreamBody inputStreamBody = new InputStreamBody(stream, (String) null);
//                FormBodyPart part = FormBodyPartBuilder.create("attachment" + nameCounter++, inputStreamBody)
//                        .addField("Command-Type", "AttachmentStream")
//                        .build();
//                entityBuilder.addPart(part);
//            }
//            request.setEntity(entityBuilder.build());
//        }

        return $request;
    }

    protected function createUrl(ServerNode $serverNode): string
    {
        $path = $serverNode->getUrl();
        $path .= '/databases/';
        $path .= $serverNode->getDatabase();
        $path .= '/bulk_docs?';

        $path .= $this->appendOptions();

        return $path;
    }

    protected function appendOptions(): string
    {
        $options = "";

        if ($this->options == null) {
            return $options;
        }

        $replicationOptions = $this->options->getReplicationOptions();
        if ($replicationOptions != null) {
            $options .= '&waitForReplicasTimeout=';
            $options .= TimeUtils::durationToTimeSpan($replicationOptions->getWaitForReplicasTimeout());

            $options .= '&throwOnTimeoutInWaitForReplicas=';
            $options .= $replicationOptions->isThrowOnTimeoutInWaitForReplicas() ? 'true' : 'false';

            $options .= '&numberOfReplicasToWaitFor=';
            $options .= $replicationOptions->isMajority() ? "majority" : $replicationOptions->getNumberOfReplicasToWaitFor();
        }

        $indexOptions = $this->options->getIndexOptions();
        if ($indexOptions != null) {
            $options .= '&waitForIndexesTimeout=';
            $options .= TimeUtils::durationToTimeSpan($indexOptions->getWaitForIndexesTimeout());

            $options .= '&waitForIndexThrow=';
            $options .= $indexOptions->isThrowOnTimeoutInWaitForIndexes() ? 'true' : 'false';

            foreach ($indexOptions->getWaitForSpecificIndexes() as $specificIndex) {
                $options .= '&waitForSpecificIndex=' . urlEncode($specificIndex);
            }
        }

        return $options;
    }

    public function setResponse(string $response, bool $fromCache): void
    {
        if (empty($response)) {
            throw new IllegalStateException('Got null response from the server after doing a batch, something is very wrong. Probably a garbled response.');
        }

        $this->result = $this->getMapper()->denormalize($response, BatchCommandResult::class);
    }

    public function isReadRequest(): bool
    {
        return false;
    }

    public function close(): void
    {
        // empty
    }

}
