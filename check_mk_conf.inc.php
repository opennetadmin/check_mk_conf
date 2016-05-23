<?php

// Lets do some initial install related stuff
if (file_exists(dirname(__FILE__)."/install.php")) {
    printmsg("DEBUG => Found install file for plugin.", 1);
    include(dirname(__FILE__)."/install.php");
}



// Make sure we have necessary functions & DB connectivity
require_once($conf['inc_functions_db']);



///////////////////////////////////////////////////////////////////////
//  Function: check_mk_conf (string $options='')
//
//  Input Options:
//    $options = key=value pairs of options for this function.
//               multiple sets of key=value pairs should be separated
//               by an "&" symbol.
//
//  Output:
//    Returns a two part list:
//      1. The exit status of the function.  0 on success, non-zero on
//         error.  All errors messages are stored in $self['error'].
//      2. A textual message for display on the console or web interface.
//
//  Example: list($status, $result) = check_mk_conf('match=test');
///////////////////////////////////////////////////////////////////////
function check_mk_conf($options="") {
    global $conf, $self, $onadb;

    // Version - UPDATE on every edit!
    $version = '1.00';
    // default search tag 
    $match = 'monitor';
    $lifecycles = array('notset');
    $tags = array();

    printmsg("DEBUG => check_mk_conf({$options}) called", 3);

    // Parse incoming options string to an array
    $options = parse_options($options);

    // Return the usage summary if we need to
    if ($options['help'] or !($options['wato_host_tags'] or $options['all_hosts'] or $options['groups'] or $options['wato_groups']) ) {
        // NOTE: Help message lines should not exceed 80 characters for proper display on a console
        $self['error'] = 'ERROR => Insufficient parameters';
        return(array(1,
<<<EOM

check_mk_conf-v{$version}
Generate various output types of check_mk configuration

  Synopsis: check_mk_conf [KEY=VALUE] ...

  Required:
    wato_host_tags    Configs for multisite. Store in multisite.d
     or
    all_hosts         Configs for check_mk. Store in conf.d/ona
     or
    wato_groups       Configs for WATO groups. Store in conf.d/wato/groups.mk
     or
    groups            Configs for check_mk. Store in conf.d/ona

  Optional:
    match=<name>      Custom attribute name to select which hosts to monitor. Default: {$match}
                      Will only match hosts that have a value other than 'N'
                      All hosts on a subnet can be excluded by setting the CA
                      for that subnet to 'N'.

wato_host_tags: Builds a list of tag groups for use inside the WATO web gui.
                This does not assoicate hosts to tags, just provides dropdowns.
                This is built from custom attributes. There is a group called
                tags that lists all tags available within ONA.

all_hosts: Extract all hosts from ONA and build check_mk tags for that host
           using Custom Attributes and tags from ONA.

wato_groups: Builds the GUI dropdown list of groups. This is for the GUI only.

groups: Build groups for all hosts and services. A group is created that
        corresponds the the lifecycle custom attribute defined on the host.
        This file must live in conf.d/wato/groups.mk to be read properly.
        
NOTE: It is expected that you will run the update-omd-from-ona.sh script to
      invoke each of these output types and place them in the proper location.

NOTE: A tag will be built to define a site. This is used for distributed
      multisite environments. By default it will use the hosts location reference
      but can be overriden by setting a custom attribute called 'cmk_site'. Since
      ONA stores site references in upper case, we will enforce that throughout.

NOTE: check_mk_conf processes all Custom Attributes except those defined in 
the ignore list into a check_mk tag named <ca_key>-<ca_value>. Also all ONA
tags are directly associated as well with no exceptions.

EOM
        ));
    }

  // Lets ignore some lame custom attribute types.
  //TODO: ignore specified list of custom attribute names
  // This could be passed in to the module and or read from a config file in the future.  for now it will be static.
  $ca_type_ignore="custom_attribute_types.name not in ('Express Service Code','Warranty / Service Expiration Date','Serial Number','Operating System','Service Tag')";

  // Set input value for our search tag
  if (isset($options['match'])) { $match = $options['match']; }

  // Build configuration for check_mk all_hosts value
  if (isset($options['all_hosts']) or isset($options['groups']) or isset($options['wato_groups'])) {
    // Query to gather all custom attributes for hosts with our monitor tag
    $q="
select h.id host_id,
       concat(dns.name,'.',b.name) fqdn,
       custom_attribute_types.name name,
       locations.reference site,
       ip_addr,
       ca.value value
from dns,
     domains b,
     custom_attributes ca,
     custom_attribute_types,
     locations,
     interfaces,
     devices,
     hosts h
where dns.domain_id = b.id
and   h.device_id = devices.id
and   locations.id = devices.location_id
and   h.primary_dns_id = dns.id
and   h.id not in ( select table_id_ref
                from custom_attributes c,
                     custom_attribute_types t
                where t.name = '{$match}'
                and c.value like 'N'
                and c.custom_attribute_type_id = t.id )
and h.id = ca.table_id_ref
and ca.table_name_ref = 'hosts'
AND ca.custom_attribute_type_id = custom_attribute_types.id
and interfaces.id = dns.interface_id
and interfaces.subnet_id not in (select c.table_id_ref
                from custom_attributes c,
                     custom_attribute_types t
                where t.name = '{$match}'
                and c.table_name_ref = 'subnets'
                and c.value like 'N'
                and c.custom_attribute_type_id = t.id )
and {$ca_type_ignore}
order BY h.id";

    // This query selects all hosts that would have been in the list above but
    // that do not have a custom attribute set at all
    $q_noca="select h.id host_id,
       concat(dns.name,'.',b.name) fqdn,
       locations.reference site,
       ip_addr
from dns,
     domains b,
     locations,
     interfaces,
     devices,
     hosts h
where dns.domain_id = b.id
and   h.device_id = devices.id
and   locations.id = devices.location_id
and   h.primary_dns_id = dns.id
and   h.id not in ( select table_id_ref
                from custom_attributes c
                where c.table_name_ref = 'hosts')
and interfaces.id = dns.interface_id
and interfaces.subnet_id not in (select c.table_id_ref
                from custom_attributes c,
                     custom_attribute_types t
                where t.name = '{$match}'
                and c.table_name_ref = 'subnets'
                and c.value like 'N'
                and c.custom_attribute_type_id = t.id )
order BY h.id";

    // exectue the query
    $rs = $onadb->Execute($q);
    if ($rs === false or (!$rs->RecordCount())) {
        $self['error'] = 'ERROR => check_mk_conf(): Custom Attribute SQL query failed: ' . $onadb->ErrorMsg();
        printmsg($self['error'], 0);
        $exit += 1;
    }

    // Loop through hosts with attributes
    while ($ca = $rs->FetchRow()) {
      // default the site to the location reference
      $site = $ca['site'];

      // CUSTOM:
      // based on IP being in the 10.100 block, force it to corp site
      if ( ($ca['ip_addr'] > 174325760 && $ca['ip_addr'] < 174391295) ) {
        $site = 'corp';
      }

      // Capture the tags
      if ( $ca['name'] == 'lifecycle' ) {
        $lifecycles[] = $ca['value'];
        // Track whether this host has a lifecycle defined
        $cmkcalist[$ca['fqdn']]['haslifecycle'] = true;
      }
      // Override the location based site with a custom attribute site
      if ( $ca['name'] == 'cmk_site' ) {
        $site = $ca['value'];
      } else {
        // build array of custom attributes 
        $cmkcalist[$ca['fqdn']]['cattributes'][$ca['name']]=$ca['value'];
      }

      // Query for tag selection
      $tag_query="
select GROUP_CONCAT(tags.name ORDER BY tags.name ASC SEPARATOR '|') tags
from hosts h
INNER JOIN tags ON (h.id = tags.reference)
where   h.id = {$ca['host_id']}
GROUP BY h.id";

      // execute tag query
      $tag_rs = $onadb->Execute($tag_query);
      $taglist = $tag_rs->FetchRow();
      // Store tags in our host array
      if ($taglist!='') $cmkcalist[$ca['fqdn']]['taglist'] = "|{$taglist['tags']}";
      // Gather up all tags we have found
      //$tags = array_merge($tags,explode("|",$taglist));

      // Clean up our site value
      $site = strtoupper(preg_replace("/[^A-Za-z0-9_.]/", '', $site));
      // Add site to the array for later
      $cmkcalist[$ca['fqdn']]['site'] = $site;
      // create the artificial custom attribute for site
      $cmkcalist[$ca['fqdn']]['cattributes']['cmk_site']=$site;


    }

    // process our hosts with no custom attribute at all
    $rsn = $onadb->Execute($q_noca);
    if ($rsn === false or (!$rsn->RecordCount())) {
        $self['error'] = 'ERROR => check_mk_conf(): No Custom Attribute SQL query failed: ' . $onadb->ErrorMsg();
        printmsg($self['error'], 0);
        $exit += 1;
    }

    while ($can = $rsn->FetchRow()) {
      // default the site to the location reference
      $site = $can['site'];

      // CUSTOM:
      // based on IP being in the 10.100 block, force it to corp site
      if ( ($can['ip_addr'] > 174325760 && $can['ip_addr'] < 174391295) ) {
        $site = 'corp';
      }

      $cmkcalist[$can['fqdn']]['site'] = $site;
      $cmkcalist[$can['fqdn']]['haslifecycle'] = false;
    }

    // close record sets
    $rs->Close();
    $rsn->Close();
    $tag_rs->Close();
  }

  if (isset($options['all_hosts'])) {
    $text = "
# This file is autogenerated by OpenNetAdmin check_mk_conf module. Do not edit here.

# Lock wato from making changes
_lock = True

# Define our hosts with a list of tags
all_hosts += [
";

    // Lets sort our list so it is handy!
    ksort($cmkcalist, SORT_NATURAL);

    // Print out our combined results for this host
    foreach ($cmkcalist as $cafqdn => $cahost) {
      // Add a notset lifecycle if one was not set
      $nolifecycle = '';
      if (!$cahost['haslifecycle']) {
        $nolifecycle = '|lifecycle-notset';
      }
      // create text from cattributes array
      $cattribute_text = '';
      foreach ($cahost['cattributes'] as $caname => $cavalue) {
        $cattribute_text = "{$cattribute_text}|{$caname}-{$cavalue}";
      }
      // print our host entry with all tag data
      $text .= "  \"{$cafqdn}|site:{$cahost['site']}{$cattribute_text}{$cahost['taglist']}{$nolifecycle}|/\" + FOLDER_PATH + \"/\",\n";
    }
    $text .= "]\n\n";


    // Set extra settings for site information
    $text .= "
# Host attributes (needed for WATO)
host_attributes.update({
";
    foreach ($cmkcalist as $cafqdn => $cahost) {
      $text .= "  '{$cafqdn}': {'site': '{$cahost['site']}', ";
      // process cattributes array
      $cattribute_text = '';
      foreach ($cahost['cattributes'] as $caname => $cavalue) {
        $text .= "'tag_ona-{$caname}': '{$cavalue}', ";
      }
      $text .= "},\n";
    }
    $text .= "})\n\n";




  } // end if all_hosts

  if (isset($options['groups']) or isset($options['wato_groups'])) {
    // This is a non generic use case.. everyone may not need this
    if (is_array($lifecycles)) {
      $lifecycles=array_unique($lifecycles);

      if (isset($options['wato_groups'])) {
        $text .= "# Autogenerated WATO host/service groups. Do not edit here.\n";
        $text .= "
if type(define_contactgroups) != dict:
    define_contactgroups = {}
define_contactgroups.update({
  'all': u'Everybody',
";
        foreach ($lifecycles as $lifecycle) {
          $text .= "  'cmk-{$lifecycle}': u'{$lifecycle}',\n";
        }
        $text .= "})\n\n";

        $text .= "
if type(define_hostgroups) != dict:
    define_hostgroups = {}
define_hostgroups.update({
";
        foreach ($lifecycles as $lifecycle) {
          $text .= "  'lifecycle-{$lifecycle}': u'{$lifecycle}',\n";
        }
        $text .= "})\n\n";

        $text .= "
if type(define_servicegroups) != dict:
    define_servicegroups = {}
define_servicegroups.update({
";
        foreach ($lifecycles as $lifecycle) {
          $text .= "  'lifecycle-{$lifecycle}': u'{$lifecycle}',\n";
        }
        $text .= "})\n\n";
      } // End wato_groups section

      // Build the group section that assigns items to their actual group
      if (isset($options['groups'])) {
        // Get a list of lifecycles and define them as host/service groups
        $text .= "# Autogenerated host/service groups. Do not edit here.\n";
        $text .= "define_hostgroups = True\n";
        $text .= "host_groups += [\n";
        foreach ($lifecycles as $lifecycle) {
          $text .= "  ('lifecycle-{$lifecycle}', ['lifecycle-{$lifecycle}'], ALL_HOSTS),\n";
        }
        $text .= "]\n\n";
  
        $text .= "define_servicegroups = True\n";
        $text .= "service_groups += [\n";
        $lifecycles=array_unique($lifecycles);
        foreach ($lifecycles as $lifecycle) {
          $text .= "  ('lifecycle-{$lifecycle}', ['lifecycle-{$lifecycle}'], ALL_HOSTS, ALL_SERVICES),\n";
        }
        $text .= "]\n\n";
  
        $text .= "# Autogenerated host/service contact groups. Do not edit here.\n";
        $text .= "define_contactgroups = True\n";
        $text .= "host_contactgroups += [\n";
        foreach ($lifecycles as $lifecycle) {
          $text .= "  ('cmk-{$lifecycle}', ['lifecycle-{$lifecycle}'], ALL_HOSTS),\n";
        }
        $text .= "]\n\n";

        $text .= "service_contactgroups += [\n";
        $lifecycles=array_unique($lifecycles);
        foreach ($lifecycles as $lifecycle) {
          $text .= "  ('cmk-{$lifecycle}', ['lifecycle-{$lifecycle}'], ALL_HOSTS, ALL_SERVICES),\n";
        }
        $text .= "]\n\n";
      } // End groups section
    }
  } // end if host_groups





  // Build wato host tags, this provides available tags to the gui
  if (isset($options['wato_host_tags'])) {

    // Pre-load our lifecycle with the 'notset' option
    $calist['lifecycle'][] = 'notset';

    // Get a list of the tags
    list($status, $rows, $tags) = db_get_records($onadb, 'tags', 'type = "host"', 'name ASC');
    foreach ($tags as $tag) {
      $taglist[]=$tag['name'];
    }
    // Get a list of all of our CA types minus the ignore list
    list($status, $rows, $ca_types) = db_get_records($onadb, 'custom_attribute_types', $ca_type_ignore, 'name ASC');
    foreach ($ca_types as $ca_type) {
      $ca_query="select distinct value from custom_attributes where custom_attribute_type_id = {$ca_type['id']} order by value ASC";
      $rs = $onadb->Execute($ca_query);
      $rows = $rs->RecordCount();
      if ($rows) {
        // Loop through record set
        while ($ca = $rs->FetchRow()) {
          // For boolean tags, show both true and false states
          if ( preg_match('/^Y$/i',$ca['value']) || preg_match('/^N$/i',$ca['value']) || preg_match('/^true$/i',$ca['value']) || preg_match('/^false$/i',$ca['value']) ) {
            $calist[$ca_type['name']][] = 'true';
            $calist[$ca_type['name']][] = 'false';
          } else {
            $calist[$ca_type['name']][] = $ca['value'];
          }
        }
      }
    }

    // Print out our custom attributes
    $text = "
# Autogenerated by OpenNetAdmin check_mk_conf module. Do not edit here.
wato_host_tags += [
";
    // Set up a list of all ONA tags
    $text .= "  ('ona-tag', u'OpenNetAdmin Tags/tag', [\n";
    $text .= "    (None, u'Undef', []),\n";
    sort($taglist, SORT_NATURAL | SORT_FLAG_CASE);
    foreach (array_unique($taglist) as $tag) {
      $text .= "    ('".strtolower($tag)."', u'".strtolower($tag)."', []),\n";
    }
    $text .= "  ]),\n";
    // create a tag out of each custom attribute type
    foreach ($calist as $catype => $ca) {
      $text .= "  ('ona-{$catype}', u'OpenNetAdmin Tags/{$catype}', [\n";
      $text .= "    (None, u'Undef', []),\n";
      $ca=array_unique($ca);
      sort($ca, SORT_NATURAL | SORT_FLAG_CASE);
      foreach ($ca as $cavalue) {
        $text .= "    ('{$catype}-".strtolower($cavalue)."', u'".strtolower($cavalue)."', []),\n";
      }
      $text .= "  ]),\n";
    }
    $text .= "]\n";

    // Close record set
    $rs->Close();
  }
/*



TODO: maybe generate host aliases???
      does not seem we can add more than one alias?  would be nice to alias all A records to a host?
# Autogenerated EC2 host aliases. Do not edit here.
extra_host_conf.setdefault('alias', []).extend([
  (u'prod01-api01', ['prod01-api01.us-west-2.kountaccess.com']),

*/

    // Return the success notice
    return(array(0, $text));
}


?>
