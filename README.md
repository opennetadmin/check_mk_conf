check_mk_conf
===========

This is a ONA plugin that enables a new `dcm.pl` command line module called `check_mk_conf`. 
Its job is to extract Custom Attribute and tag information from ONA and build configuration
to be used by the check_mk/OMD/multisite systems.  Specifically it builds the 'all_hosts'
variable with the hosts in ONA that are enabled for monitoring.  It will tag those hosts using
both ONA tags and custom attribute information in the form of `<ca_key>-<ca_value>`

Install
=======

Install as a standard plugin for ONA.

Usage
=====

```
check_mk_conf-v1.00
Generate check_mk configuration of specified type

  Synopsis: check_mk_conf [KEY=VALUE] ...

  Required:
    wato_host_tags    Configs for multisite. Store in multisite.d
    all_hosts         Configs for check_mk. Store in conf.d

  Optional:
    tag=<name>  Tag name to select which hosts to monitor. Default: monitor

Processes all Custom Attributes except those defined in the ignore list into a
check_mk tag named <ca_key>-<ca_value>.  Also all ONA tags are directly associated as well.

Wato host tags are only comprised of custom attributes from ONA.
```
pass the module the tag option to select hosts that should be built into the all_hosts variable.
By default any host with the 'monitor' tag will be pulled in. You can set individual
hosts with a monitor flag of Y or N.  You can also set a subnet monitor flag
to N and this will disable all hosts on that subnet.

There are two modes to run in `wato_host_tags` and `all_hosts`.

`wato_host_tags` is intended to configure multisite.d/ona.conf or similar
`all_hosts` is intended to configure conf.d/ona.mk or similar

You should issue a omd -I and an omd -O after you have updated those files using this module

