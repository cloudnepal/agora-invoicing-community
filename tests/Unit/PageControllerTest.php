<?php

namespace Tests\Unit;

use App\User;
use App\Model\Front\FrontendPage;
use Tests\TestCase;

class PageControllerTest extends TestCase
{
    /**
     * A basic unit test example.
     *
     * @return void
     */
    public function test_createpage_returnstatus200()
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user);

        $response = $this->post('/pages', [
            'name'=>'demo',
            'slug'=> 'demopass',
            'url' => 'http://demo.com',
            'publish' => 'yes',
            'content' => 'Here the new page created',
        ]);
        $this->assertDatabaseHas('frontend_pages', ['slug'=>'contact-us']);
    }

    public function test_validation_fails_if_required_field_empty()
    {
        $rules = [
            'name' => 'required|unique:frontend_pages,name',
            'publish' => 'required',
            'slug' => 'required',
            'url' => 'required',
            'content' => 'required',
        ];

        $data = [
            'name' => 'contact',
            'publish' => '2016-06-06 08:47:41',
            'slug' => 'demo',
            'url' => 'https://demo.com',
            'content' => 'hkshd ksdh kzhdd',
        ];
        $v = $this->app['validator']->make($data, $rules);
        $this->assertTrue($v->passes());
    }
    public function test_updatepage_returnstatus200()
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user);
        $page = FrontendPage::create(['name'=>'demo',
            'slug'=> 'demopass',
            'url' => 'http://demo.com',
            'publish' => 'yes',
            'content' => 'Here the new page created']);

          $response = $this->post('/pages',[
            'id'=> $page->id,
            'name'=>'Contact Us',
            'slug'=> 'demopass',
            'url' => 'http://demo.com',
            'publish' => 'yes',
            'content' => 'Here the new page created'
        ]);
        $this->assertDatabaseHas('frontend_pages', ['name'=>'Contact Us']);


    }
}