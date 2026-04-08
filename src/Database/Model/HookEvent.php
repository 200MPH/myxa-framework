<?php

declare(strict_types=1);

namespace Myxa\Database\Model;

enum HookEvent: string
{
    case BeforeSave = 'before_save';
    case AfterSave = 'after_save';
    case BeforeUpdate = 'before_update';
    case AfterUpdate = 'after_update';
    case BeforeDelete = 'before_delete';
    case AfterDelete = 'after_delete';
}
