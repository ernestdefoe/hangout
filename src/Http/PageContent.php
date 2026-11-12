<?php

namespace ErnestDefoe\Hangout\Http;

use Flarum\Frontend\Document;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;
use Psr\Http\Message\ServerRequestInterface;

/** /hangout as the server sends it: a title, a canonical URL, and noindex (the world needs JavaScript). */
class PageContent
{
    public function __construct(
        private readonly UrlGenerator $url,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Document $document, ServerRequestInterface $request): Document
    {
        $document->title = (string) $this->translator->trans('ernestdefoe-hangout.forum.page.title');
        $document->canonicalUrl = $this->url->to('forum')->route('hangout');
        $document->meta['description'] = (string) $this->translator->trans('ernestdefoe-hangout.forum.page.description');
        $document->meta['robots'] = 'noindex, nofollow';

        return $document;
    }
}
