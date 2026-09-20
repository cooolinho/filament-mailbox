<?php

namespace Cooolinho\FilamentMailbox\Enums;

enum LabelSource: string
{
    case ImapKeyword = 'imap_keyword';
    case Gmail = 'gmail';
    case GraphCategory = 'graph_category';
}
