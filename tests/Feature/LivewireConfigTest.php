<?php

test('livewire temporary uploads allow larger database imports', function () {
    expect(config('livewire.temporary_file_upload.rules'))->toBe([
        'required',
        'file',
        'max:102400',
    ]);

    expect(config('livewire.temporary_file_upload.max_upload_time'))->toBe(15);
});
