    // BEGIN PADDLE CHECKOUT INSTALLER — managed by install.sh, re-run to update
    Route::get('/payments/paddle/checkout', [Client\PaymentsController::class, 'paddleCheckout'])->name('payments.paddle.checkout');
    // END PADDLE CHECKOUT INSTALLER
