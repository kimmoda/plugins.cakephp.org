require 'rubygems'
require 'god'

ROOT = "/apps/production/cakepackages.com/default"
require File.expand_path(File.dirname(__FILE__) + '/cakephp_god.rb')
God.pid_file_directory = "/apps/pids"
God.log_level = :error

%w(default).each do |queue|
  CakePHPGod.queue_workers(queue, 1)
end

# run as root
%w(root).each do |queue|
  CakePHPGod.queue_workers(queue, 1, 'root', 'root')
end
