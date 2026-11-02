<?php
/**
 * Form field names in the plugin's views (issue #2).
 *
 * Inside Form->create('Model'), CakePHP 2's Helper::setEntity() adds the
 * model scope only to a one-part name ('nonce'), a two-part name ending in a
 * date suffix ('date.month'), or a name starting with a number ('0.name').
 * Any other dotted name ('choices.' . $id) posts outside data[Model], where a
 * controller reading $this->request->data['Model'] never sees it. So every
 * literal field name with a dot must start with its model name.
 *
 * A static scan of View/, because only a rendered form shows the posted
 * names and no other test renders every view.
 */

class ViewFieldNamesTest extends AteTestCase {

  /** A dotted Form field name literal must start with a model name. */
  public function testDottedFormFieldNamesNameTheirModel() {
    $root = App::pluginPath('ApplicationTeamEnroller');
    $bad = array();

    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . 'View'));
    foreach($it as $file) {
      if(!$file->isFile() || is_link($file->getPathname())
         || !preg_match('/\.(ctp|inc)$/', $file->getFilename())) {
        continue;
      }

      foreach(file($file->getPathname()) as $i => $line) {
        preg_match_all('/->Form->\w+\(\s*\'([^\']*)\'/', $line, $m);

        foreach($m[1] as $name) {
          if(strpos($name, '.') !== false && !preg_match('/^[A-Z]/', $name)) {
            $bad[] = substr($file->getPathname(), strlen($root)) . ':' . ($i + 1) . " '" . $name . "'";
          }
        }
      }
    }

    $this->assertEqual(array(), $bad,
      'Form field names with a dot must start with the model name (\'AteResponse.choices.\' . $id, '
      . 'not \'choices.\' . $id): CakePHP 2 posts the unscoped name outside data[Model]');
  }
}
