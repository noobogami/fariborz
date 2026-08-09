<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // No test may touch the network. Laravel EXECUTES any request that matches
        // no Http::fake() stub, so without this a forgotten stub quietly hits
        // whatever happens to be running on the dev machine — which is how tests
        // start passing (or failing) for reasons unrelated to the code. Every
        // planner turn now reads the LLM gateway's model catalogue, so the surface
        // for that is much wider than it was.
        Http::preventStrayRequests();
    }
}
