<?php

namespace ErnestDefoe\Hangout\Api;

use ErnestDefoe\Hangout\HangoutException;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Plain JSON endpoints: Hangout's answers are not JSON:API resources, and every one is no-store. */
abstract class Controller implements RequestHandlerInterface
{
    abstract protected function answer(ServerRequestInterface $request, User $actor): array;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $data = $this->answer($request, RequestUtil::getActor($request));
        } catch (HangoutException $e) {
            return self::error($e->status, $e->errorCode, $e->meta);
        } catch (PermissionDeniedException) {
            return self::error(403, 'permission_denied');
        }

        return new JsonResponse($data + ['now' => time()], 200, ['Cache-Control' => 'no-store'], JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string, mixed> $meta */
    public static function error(int $status, string $code, array $meta = []): JsonResponse
    {
        $error = ['status' => (string) $status, 'code' => 'hangout_'.$code];
        if ($meta) {
            $error['meta'] = $meta;
        }

        return new JsonResponse(['errors' => [$error]], $status, ['Cache-Control' => 'no-store']);
    }

    /** @return array<string, mixed> */
    protected static function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }
}
